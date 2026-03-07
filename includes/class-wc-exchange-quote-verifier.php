<?php
/**
 * Проверка поступления LTC на адрес заказа (chain.so / BlockCypher) и обновление статуса WooCommerce.
 */

defined('ABSPATH') || exit;

class WC_Exchange_Quote_Verifier {

    const CRON_HOOK = 'woo_exchange_quote_check_payments';
    const OPTION_VERIFY_ENABLED = 'woo_exchange_quote_verify_enabled';
    const OPTION_VERIFY_CONFIRMATIONS = 'woo_exchange_quote_verify_confirmations';

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_check'));
        add_action('woocommerce_update_options_payment_gateways_exchange_quote', array(__CLASS__, 'maybe_schedule_cron'));
        add_filter('cron_schedules', array(__CLASS__, 'add_interval'));
        add_action('woocommerce_init', array(__CLASS__, 'maybe_schedule_cron'));
        register_deactivation_hook(plugin_dir_path(dirname(__FILE__)) . 'woo-exchange-quote-gateway.php', array(__CLASS__, 'unschedule'));
    }

    public static function add_interval($schedules) {
        if (!isset($schedules['woo_eq_five_min'])) {
            $schedules['woo_eq_five_min'] = array(
                'interval' => 300,
                'display'  => __('Каждые 5 минут', 'woo-exchange-quote-gateway'),
            );
        }
        return $schedules;
    }

    public static function maybe_schedule_cron() {
        $gateway = WC()->payment_gateways()->payment_gateways()['exchange_quote'] ?? null;
        if (!$gateway || $gateway->get_option('enable_verify') !== 'yes') {
            self::unschedule();
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'woo_eq_five_min', self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Запуск проверки: pending заказы exchange_quote → запрос баланса по адресу → при поступлении суммы — оплачен.
     */
    public static function run_check() {
        $gateway = WC()->payment_gateways()->payment_gateways()['exchange_quote'] ?? null;
        if (!$gateway || $gateway->get_option('enable_verify') !== 'yes') {
            return;
        }

        // Проверяем заказы в on-hold (ожидание крипто-платежа).
        $orders = wc_get_orders(array(
            'status'         => 'on-hold',
            'payment_method' => 'exchange_quote',
            'limit'          => 50,
            'return'         => 'ids',
        ));

        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            $address = $order->get_meta('_exchange_quote_ltc_address');
            $expected = $order->get_meta('_exchange_quote_ltc_amount');
            if ($address === '' || $expected === '' || (float) $expected <= 0) {
                continue;
            }
            $expected_float = (float) $expected;
            $received = self::get_address_received_ltc($address, $gateway);
            if ($received === null) {
                continue;
            }
            $use_unconfirmed = $gateway->get_option('verify_use_unconfirmed') === 'yes';
            $balance = $use_unconfirmed ? ($received['confirmed'] + $received['unconfirmed']) : $received['confirmed'];
            $tolerance = 0.00001;
            if ($balance >= $expected_float - $tolerance) {
                // Ключи для колонки «Amount received» в списке ордеров.
                $order->update_meta_data('crypto_amount_received', $balance);
                $order->update_meta_data('_crypto_amount_received', $balance);
                $order->save();
                // Сумма подтверждена — переводим on-hold → pending (ждёт обработки).
                $order->update_status('pending', sprintf(
                    __('Оплата LTC подтверждена. Ожидалось: %s LTC, получено (confirmed: %s, unconfirmed: %s).', 'woo-exchange-quote-gateway'),
                    $expected_float,
                    $received['confirmed'],
                    $received['unconfirmed']
                ));
                if (function_exists('wc_reduce_stock_levels')) {
                    wc_reduce_stock_levels($order_id);
                }
                self::log($gateway, 'Order ' . $order_id . ' confirmed: on-hold → pending. Balance ' . $balance . ' >= ' . $expected_float);
            }
        }
    }

    /**
     * Запрос полученной суммы на LTC-адресе. Возвращает ['confirmed' => float, 'unconfirmed' => float] или null.
     */
    protected static function get_address_received_ltc($address, $gateway) {
        $api = $gateway->get_option('verify_api', 'chain_so');
        if ($api === 'blockcypher') {
            return self::fetch_balance_blockcypher($address);
        }
        return self::fetch_balance_chain_so($address);
    }

    protected static function fetch_balance_chain_so($address) {
        $url = 'https://chain.so/api/v2/get_address_balance/LTC/' . rawurlencode($address);
        $resp = wp_remote_get($url, array('timeout' => 15));
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($json['status']) || $json['status'] !== 'success' || !isset($json['data'])) {
            return null;
        }
        $d = $json['data'];
        $confirmed = isset($d['confirmed_balance']) ? (float) $d['confirmed_balance'] : 0.0;
        $unconfirmed = isset($d['unconfirmed_balance']) ? (float) $d['unconfirmed_balance'] : 0.0;
        return array('confirmed' => $confirmed, 'unconfirmed' => $unconfirmed);
    }

    protected static function fetch_balance_blockcypher($address) {
        $url = 'https://api.blockcypher.com/v1/ltc/main/addrs/' . rawurlencode($address) . '/balance';
        $resp = wp_remote_get($url, array('timeout' => 15));
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (!isset($json['balance']) && !isset($json['unconfirmed_balance'])) {
            return null;
        }
        $sat = (int) (isset($json['balance']) ? $json['balance'] : 0);
        $unconf_sat = (int) (isset($json['unconfirmed_balance']) ? $json['unconfirmed_balance'] : 0);
        $confirmed = $sat / 1e8;
        $unconfirmed = $unconf_sat / 1e8;
        return array('confirmed' => $confirmed, 'unconfirmed' => $unconfirmed);
    }

    protected static function log($gateway, $message) {
        if ($gateway->get_option('enable_log') !== 'yes') {
            return;
        }
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug($message, array('source' => 'exchange-quote-verifier'));
        }
    }
}
