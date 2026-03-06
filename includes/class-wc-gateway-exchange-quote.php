<?php
/**
 * WooCommerce Payment Gateway: оплата «картой» с курсом обмена и редиректом на крипто-оплату LTC.
 * Котировка берётся из Exchange Quote API (парсер, Revolut). На странице оплаты подставляются сумма в GBP и адрес LTC.
 */

defined('ABSPATH') || exit;

class WC_Gateway_Exchange_Quote extends WC_Payment_Gateway {

    const PAYMENT_METHOD_ID       = 'exchange_quote';
    const ADDRESS_LOG_OPTION      = 'woo_exchange_quote_address_log';
    const ADDRESS_LOG_MAX_ENTRIES = 100;

    public function __construct() {
        $this->id                 = self::PAYMENT_METHOD_ID;
        $this->method_title       = __('Exchange Quote — card payment (LTC)', 'woo-exchange-quote-gateway');
        $this->method_description = __('Customer sees card payment with Revolut rate and amount in GBP and LTC. After checkout, redirect to payment page with amount and LTC address. Order stays pending until crypto is confirmed.', 'woo-exchange-quote-gateway');
        $this->has_fields         = true;
        $this->supports           = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled     = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_assets'));
        add_action('wp_ajax_woo_exchange_quote_get_quote', array($this, 'ajax_get_quote'));
        add_action('wp_ajax_nopriv_woo_exchange_quote_get_quote', array($this, 'ajax_get_quote'));
        add_action('wp_ajax_woo_exchange_quote_payment_status', array($this, 'ajax_payment_status'));
        add_action('wp_ajax_nopriv_woo_exchange_quote_payment_status', array($this, 'ajax_payment_status'));
        add_action('woocommerce_api_exchange_quote_redirect', array($this, 'show_redirect_to_fluid'));
        // Хук для основных настроек WC (если понадобится); на странице способа оплаты WC вызывает generate_*_html()
        add_action('woocommerce_admin_field_address_log', array($this, 'render_address_log_field'), 10, 1);
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Включить', 'woo-exchange-quote-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Включить способ оплаты Exchange Quote (карта → LTC)', 'woo-exchange-quote-gateway'),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __('Payment method title', 'woo-exchange-quote-gateway'),
                'type'        => 'text',
                'description' => __('Title shown in cart and checkout (e.g. Card payment — Revolut rate).', 'woo-exchange-quote-gateway'),
                'default'     => __('Card payment (Revolut rate)', 'woo-exchange-quote-gateway'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'woo-exchange-quote-gateway'),
                'type'        => 'textarea',
                'description' => __('Short text under the payment method. Quote (X GBP = X LTC) is shown below from API.', 'woo-exchange-quote-gateway'),
                'default'     => __('Pay in GBP at Revolut rate; we receive LTC. Amount at current rate is shown below.', 'woo-exchange-quote-gateway'),
                'desc_tip'    => true,
            ),
            'api_base_url' => array(
                'title'       => __('Quote API URL (required for rate)', 'woo-exchange-quote-gateway'),
                'type'        => 'url',
                'description' => __('Base URL of your Exchange Quote API. Default: Fly.io demo. The API obtains and refreshes the Meld session token automatically.', 'woo-exchange-quote-gateway'),
                'default'     => 'https://exchange-quote-api.fly.dev',
                'desc_tip'    => true,
            ),
            'payment_page_url' => array(
                'title'       => __('URL страницы оплаты', 'woo-exchange-quote-gateway'),
                'type'        => 'url',
                'description' => __('Куда перекинуть после нажатия оплаты. Плейсхолдеры: {amount_gbp}, {amount_ltc}, {ltc_address}, {order_id}. Пусто — редирект на fluidmoney.xyz с суммой и адресом LTC.', 'woo-exchange-quote-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'use_hd_wallet' => array(
                'title'       => __('HD Wallet (LTC из Ltub)', 'woo-exchange-quote-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Генерировать LTC-адреса только из расширенного публичного ключа (Ltub). Каждый заказ — свой адрес.', 'woo-exchange-quote-gateway'),
                'default'     => 'yes',
                'description' => __('Все адреса выводятся из одного Ltub. Локальный вывод в плагине или через API /api/v1/hd/derive. Резервный адрес не используется.', 'woo-exchange-quote-gateway'),
            ),
            'ltc_xpub' => array(
                'title'       => __('LTC расширенный публичный ключ (Ltub)', 'woo-exchange-quote-gateway'),
                'type'        => 'text',
                'description' => __('Единственный источник адресов: Ltub (Litecoin mainnet). Каждый заказ получает свой адрес по индексу 0, 1, 2…', 'woo-exchange-quote-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'country_code' => array(
                'title'       => __('Код страны', 'woo-exchange-quote-gateway'),
                'type'        => 'text',
                'description' => __('ISO 3166-1 alpha-2 для запроса котировок (например GB).', 'woo-exchange-quote-gateway'),
                'default'     => 'GB',
                'desc_tip'    => true,
            ),
            'source_currency' => array(
                'title'       => __('Валюта корзины (фиат)', 'woo-exchange-quote-gateway'),
                'type'        => 'text',
                'description' => __('Код валюты заказа (GBP, USD, EUR и т.д.).', 'woo-exchange-quote-gateway'),
                'default'     => 'GBP',
                'desc_tip'    => true,
            ),
            'destination_crypto' => array(
                'title'       => __('Криптовалюта получения', 'woo-exchange-quote-gateway'),
                'type'        => 'text',
                'description' => __('Код крипты (LTC, BTC, ETH и т.д.).', 'woo-exchange-quote-gateway'),
                'default'     => 'LTC',
                'desc_tip'    => true,
            ),
            'provider_filter' => array(
                'title'       => __('Провайдер котировки', 'woo-exchange-quote-gateway'),
                'type'        => 'text',
                'description' => __('Провайдер для курса (например REVOLUT). Один или несколько через запятую.', 'woo-exchange-quote-gateway'),
                'default'     => 'REVOLUT',
                'desc_tip'    => true,
            ),
            'enable_verify' => array(
                'title'       => __('Проверка оплаты LTC', 'woo-exchange-quote-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Проверять поступление LTC на адрес заказа и помечать заказ оплаченным (cron каждые 5 мин).', 'woo-exchange-quote-gateway'),
                'default'     => 'no',
                'description' => __('Используется API chain.so или BlockCypher. После успешной проверки заказ переводится в «Оплачен», списывается товар.', 'woo-exchange-quote-gateway'),
            ),
            'verify_api' => array(
                'title'       => __('API для проверки баланса', 'woo-exchange-quote-gateway'),
                'type'        => 'select',
                'options'     => array(
                    'chain_so'     => 'Chain.so',
                    'blockcypher'  => 'BlockCypher',
                ),
                'default'     => 'chain_so',
                'description' => __('Сервис для запроса баланса LTC-адреса.', 'woo-exchange-quote-gateway'),
            ),
            'verify_use_unconfirmed' => array(
                'title'   => __('Учитывать неподтверждённые (0-conf)', 'woo-exchange-quote-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Считать оплату полученной при поступлении и до подтверждения в блоке.', 'woo-exchange-quote-gateway'),
                'default' => 'no',
            ),
            'enable_log' => array(
                'title'   => __('Логи', 'woo-exchange-quote-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Включить логирование (WooCommerce → Status → Logs).', 'woo-exchange-quote-gateway'),
                'default' => 'no',
            ),
            'address_log_section' => array(
                'type' => 'title',
                'title' => __('Логи сгенерированных адресов', 'woo-exchange-quote-gateway'),
                'description' => __('Ниже — последние сгенерированные адреса для оплаты (дата, время, сумма, email).', 'woo-exchange-quote-gateway'),
            ),
            'address_log' => array(
                'type' => 'address_log',
                'title' => '',
            ),
        );
    }

    public function payment_fields() {
        if ($this->description) {
            echo wp_kses_post(wpautop($this->description));
        }
        echo '<div id="woo-exchange-quote-summary" class="woo-exchange-quote-summary" style="margin-top:10px;padding:12px;background:#f8f8f8;border-radius:6px;display:none;">';
        echo '<p class="woo-eq-loading">' . esc_html__('Загрузка курса…', 'woo-exchange-quote-gateway') . '</p>';
        echo '<p class="woo-eq-result" style="display:none;"></p>';
        echo '<p class="woo-eq-error" style="display:none;color:#b32d2e;"></p>';
        echo '</div>';
    }

    public function enqueue_checkout_assets() {
        if (!is_checkout() || !$this->is_available()) {
            return;
        }

        $api_base = $this->get_option('api_base_url');
        $src_currency = $this->get_option('source_currency', 'GBP');
        $dst_crypto = $this->get_option('destination_crypto', 'LTC');
        $country = $this->get_option('country_code', 'GB');
        $provider = $this->get_option('provider_filter', 'REVOLUT');
        $provider_arr = array_map('trim', explode(',', $provider));
        $provider_arr = array_filter($provider_arr);

        wp_enqueue_script(
            'woo-exchange-quote-checkout',
            plugin_dir_url(dirname(__FILE__)) . 'assets/checkout.js',
            array('jquery'),
            '1.0.0',
            true
        );
        wp_localize_script('woo-exchange-quote-checkout', 'wooExchangeQuote', array(
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('woo_exchange_quote_nonce'),
            'api_base_url' => $api_base ? rtrim($api_base, '/') : '',
            'source_currency' => $src_currency,
            'destination_crypto' => $dst_crypto,
            'country_code' => $country,
            'provider_filter' => $provider_arr,
            'strings' => array(
                'loading'  => __('Loading rate…', 'woo-exchange-quote-gateway'),
                'error'   => __('Could not get rate. Check the amount and try again.', 'woo-exchange-quote-gateway'),
                'summary' => __('At current rate: %1$s %2$s = %3$s %4$s', 'woo-exchange-quote-gateway'),
                'no_quote' => __('You will be redirected to the payment page after placing the order.', 'woo-exchange-quote-gateway'),
            ),
        ));
    }

    /**
     * AJAX: получить котировку для суммы заказа (вызывается с checkout при смене способа оплаты или суммы).
     */
    /**
     * AJAX: get quote for checkout. Calls your Quote API (api_base_url); the API obtains and refreshes Meld token automatically.
     */
    public function ajax_get_quote() {
        check_ajax_referer('woo_exchange_quote_nonce', 'nonce');

        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        if ($amount <= 0) {
            wp_send_json_error(array('message' => __('Invalid amount.', 'woo-exchange-quote-gateway')));
        }

        $api_base = $this->get_option('api_base_url');
        if (empty($api_base)) {
            wp_send_json_success(array(
                'no_quote' => true,
                'source_amount' => $amount,
                'source_currency' => $this->get_option('source_currency', 'GBP'),
                'destination_amount' => null,
                'destination_currency' => $this->get_option('destination_crypto', 'LTC'),
                'exchange_rate' => null,
                'provider' => '',
            ));
        }

        $provider_filter = array_filter(array_map('trim', explode(',', $this->get_option('provider_filter', 'REVOLUT'))));
        $url = rtrim($api_base, '/') . '/api/v1/quote';
        $body = array(
            'country_code'             => $this->get_option('country_code', 'GB'),
            'source_currency_code'     => $this->get_option('source_currency', 'GBP'),
            'destination_currency_code' => $this->get_option('destination_crypto', 'LTC'),
            'source_amount'             => $amount,
            'payment_method_type'      => 'CREDIT_DEBIT_CARD',
            'provider_filter'          => $provider_filter,
        );

        $resp = wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));

        $this->log('Quote request: ' . $url);
        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => $resp->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($resp);
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || empty($json['success']) || empty($json['quotes'][0])) {
            $msg = isset($json['error']) ? $json['error'] : __('Quotes unavailable.', 'woo-exchange-quote-gateway');
            wp_send_json_error(array('message' => $msg));
        }

        $best = $json['quotes'][0];
        wp_send_json_success(array(
            'source_amount'        => (float) $best['source_amount'],
            'source_currency'      => $best['source_currency_code'],
            'destination_amount'   => (float) $best['destination_amount'],
            'destination_currency' => $best['destination_currency_code'],
            'exchange_rate'        => isset($best['exchange_rate']) ? (float) $best['exchange_rate'] : 0,
            'provider'             => isset($best['service_provider']) ? $best['service_provider'] : '',
        ));
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array('result' => 'failure', 'redirect' => '');
        }

        $total = (float) $order->get_total();
        $currency = $order->get_currency();
        $api_base = $this->get_option('api_base_url');

        // Даём CryptoWoo / другим плагинам возможность записать адрес в мету заказа до запроса котировки и редиректа
        do_action('woo_exchange_quote_before_get_ltc_address', $order_id, $order);
        $ltc_address = $this->get_ltc_address_for_order($order_id, $order);

        // Сохраняем адрес и сумму LTC в мету заказа (для отображения в админке и для Woo crypto)
        if ($ltc_address) {
            $order->update_meta_data('_exchange_quote_ltc_address', $ltc_address);
        }

        if ($total > 0) {
            $quote = $this->fetch_quote($total, $ltc_address);
            if (!empty($quote['destination_amount'])) {
                $order->update_meta_data('_exchange_quote_ltc_amount', $quote['destination_amount']);
                $order->update_meta_data('_exchange_quote_source_amount', $quote['source_amount']);
                $order->update_meta_data('_exchange_quote_destination_currency', $quote['destination_currency'] ?? $this->get_option('destination_crypto', 'LTC'));
            }
        }
        // Один раз при нажатии «Оформить заказ»: URL Fluid с суммой, адресом (walletAddress) и expectedDestinationAmount — как в main.py (payload walletAddress)
        $fluid_url = $this->build_payment_redirect_url($order, $total, $ltc_address);
        $order->update_meta_data('_exchange_quote_fluid_redirect_url', $fluid_url);
        $order->save();

        $order->update_status('pending', __('Ожидание оплаты (крипто). Клиент перенаправлен на страницу оплаты.', 'woo-exchange-quote-gateway'));
        $this->log('Redirect order ' . $order_id . ' to ' . $fluid_url);
        if ($ltc_address !== '') {
            $this->log_generated_address($order, $ltc_address);
        }

        // Страница «Переход на страницу оплаты» затем редирект на Fluid по сохранённому URL (без повторного вывода адреса)
        $redirect_page = add_query_arg(array(
            'wc-api'   => 'exchange_quote_redirect',
            'order_id' => $order_id,
            'key'      => $order->get_order_key(),
        ), home_url('/'));

        return array(
            'result'   => 'success',
            'redirect' => $redirect_page,
        );
    }

    /**
     * AJAX: статус оплаты заказа (для опроса со страницы ожидания). Проверка по order_id + key.
     */
    public function ajax_payment_status() {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key      = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if (!$order_id || !$key) {
            wp_send_json_error(array('message' => 'invalid'));
        }
        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $key) {
            wp_send_json_error(array('message' => 'invalid'));
        }
        $order_received_url = add_query_arg('key', $order->get_order_key(), wc_get_endpoint_url('order-received', $order_id, wc_get_checkout_url()));
        $address           = $order->get_meta('_exchange_quote_ltc_address');
        $status             = $order->get_status();
        wp_send_json_success(array(
            'status'              => $status,
            'order_received_url'  => $order_received_url,
            'address'             => $address,
            'paid'                => in_array($status, array('processing', 'completed'), true),
        ));
    }

    /**
     * После checkout: одна страница с модальным окном (X GBP = X LTC из котировки) → редирект на Fluid в этой же вкладке.
     * step=wait: страница ожидания (обратный отсчёт, адрес, опрос статуса) — доступна по прямой ссылке при необходимости.
     */
    public function show_redirect_to_fluid() {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key      = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if (!$order_id || !$key) {
            wp_safe_redirect(wc_get_page_permalink('checkout'));
            exit;
        }
        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $key) {
            wp_safe_redirect(wc_get_page_permalink('checkout'));
            exit;
        }

        $total      = (float) $order->get_total();
        $currency   = $order->get_currency();
        $ltc_amount = $order->get_meta('_exchange_quote_ltc_amount');
        $ltc_amount = $ltc_amount !== '' ? (float) $ltc_amount : null;
        $fluid_url  = $order->get_meta('_exchange_quote_fluid_redirect_url');
        if ($fluid_url === '' || ! is_string($fluid_url)) {
            $fluid_url = $this->build_payment_redirect_url($order, $total, $order->get_meta('_exchange_quote_ltc_address'));
        }

        $step = isset($_GET['step']) ? sanitize_text_field(wp_unslash($_GET['step'])) : '';
        if ($step === 'wait') {
            $order_received_url = add_query_arg('key', $order->get_order_key(), wc_get_endpoint_url('order-received', $order_id, wc_get_checkout_url()));
            $this->render_payment_wait_page($order_id, $order, $order->get_meta('_exchange_quote_ltc_address'), $order_received_url);
            return;
        }

        $redirect_sec = 5;
        $gbp_formatted = number_format($total, 2, '.', ' ');
        $ltc_formatted = $ltc_amount !== null ? number_format($ltc_amount, 8, '.', ' ') : '';

        // Данные для генерации страницы в браузере из blob (прокладка: без реферера при переходе на Fluid).
        $payload = array(
            'title'       => 'Redirect to payment',
            'quoteLine'   => $ltc_formatted !== ''
                ? sprintf('Order #%s. Quote: %s %s = %s LTC.', $order_id, $gbp_formatted, $currency, $ltc_formatted)
                : sprintf('Order #%s. Pay: %s %s. LTC amount on payment page.', $order_id, $gbp_formatted, $currency),
            'goBtn'       => 'Continue to payment (Fluid)',
            'waitMsg'     => sprintf('Redirecting in %d seconds…', $redirect_sec),
            'fallback'    => 'If not redirected, click here',
            'fluidUrl'    => $fluid_url,
            'redirectSec' => (int) $redirect_sec,
        );

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        ?>
<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Redirect</title>
<style>
body{margin:0;font-family:system-ui,sans-serif;background:rgba(0,0,0,.45);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;}
.eq-modal{background:#fff;max-width:440px;width:100%;padding:1.75rem;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.2);text-align:center;}
.eq-modal h2{margin:0 0 1rem;font-size:1.25rem;}
.eq-quote{margin:1rem 0;font-size:1.15rem;font-weight:600;color:#1d2327;}
.eq-btn{display:inline-block;margin-top:1rem;padding:0.75rem 1.5rem;background:#0073aa;color:#fff!important;text-decoration:none;border-radius:6px;font-size:1rem;}
.eq-btn:hover{background:#005a87;color:#fff!important;}
.eq-wait{color:#50575e;font-size:0.9rem;margin-top:1rem;}
.eq-fallback{margin-top:1rem;font-size:0.85rem;}
.eq-fallback a{color:#0073aa;}
</style>
</head><body>
<script>
(function(){
  var payload = <?php echo wp_json_encode($payload); ?>;
  var htmlBlob = [
    '<div class="eq-modal" role="dialog" aria-labelledby="eq-title">',
    '  <h2 id="eq-title">' + (payload.title || 'Redirect to payment') + '</h2>',
    '  <p class="eq-quote">' + (payload.quoteLine || '') + '</p>',
    '  <p><a class="eq-btn" href="' + (payload.fluidUrl || '#') + '" rel="noopener noreferrer">' + (payload.goBtn || 'Continue') + '</a></p>',
    '  <p class="eq-wait">' + (payload.waitMsg || '') + '</p>',
    '  <p class="eq-fallback"><a href="' + (payload.fluidUrl || '#') + '" rel="noopener noreferrer">' + (payload.fallback || 'Click here') + '</a></p>',
    '</div>'
  ].join('');
  document.body.innerHTML = htmlBlob;
  document.title = payload.title || 'Redirect';
  var fluidUrl = payload.fluidUrl;
  var sec = payload.redirectSec || 5;
  if (window.self !== window.top) {
    window.top.location.href = window.location.href || window.location.toString();
    return;
  }
  setTimeout(function(){ window.location.replace(fluidUrl); }, sec * 1000);
})();
</script>
</body></html>
        <?php
        exit;
    }

    /**
     * Страница ожидания оплаты: обратный отсчёт 30 мин, спиннер, адрес, TX ID (появится после платежа), опрос статуса, редирект на «Заказ получен».
     */
    protected function render_payment_wait_page($order_id, $order, $address, $order_received_url) {
        $countdown_min = 30;
        $title         = __('Ожидание оплаты', 'woo-exchange-quote-gateway');
        $ajax_url      = add_query_arg(array(
            'action'   => 'woo_exchange_quote_payment_status',
            'order_id' => $order_id,
            'key'      => $order->get_order_key(),
        ), admin_url('admin-ajax.php'));

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html($title); ?></title>
<style>
body{margin:0;font-family:system-ui,sans-serif;background:#f5f5f5;min-height:100vh;padding:20px;box-sizing:border-box;}
.eq-wait-page{max-width:480px;margin:0 auto;background:#fff;padding:1.5rem;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.08);text-align:center;}
.eq-wait-page h1{margin:0 0 1rem;font-size:1.35rem;}
.eq-countdown{font-size:1.5rem;font-weight:700;color:#0073aa;margin:1rem 0;}
.eq-spinner{width:40px;height:40px;margin:1rem auto;border:3px solid #e0e0e0;border-top-color:#0073aa;border-radius:50%;animation:eq-spin .8s linear infinite;}
@keyframes eq-spin{to{transform:rotate(360deg);}}
.eq-addr{word-break:break-all;font-size:0.9rem;color:#333;background:#f9f9f9;padding:0.75rem;border-radius:4px;margin:1rem 0;}
.eq-tx{font-size:0.9rem;color:#666;margin:0.5rem 0;}
.eq-status{margin:1rem 0;font-weight:600;color:#0a0;}
.eq-redirect{color:#666;font-size:0.9rem;}
</style>
</head><body>
<div class="eq-wait-page">
  <h1><?php echo esc_html($title); ?></h1>
  <p class="eq-countdown" id="eq-countdown">30:00</p>
  <p><?php esc_html_e('Время на оплату (обратный отсчёт)', 'woo-exchange-quote-gateway'); ?></p>
  <div class="eq-spinner" id="eq-spinner" aria-hidden="true"></div>
  <p><?php esc_html_e('Адрес, на который перенаправили в Fluid:', 'woo-exchange-quote-gateway'); ?></p>
  <p class="eq-addr" id="eq-address"><?php echo esc_html($address); ?></p>
  <p class="eq-tx"><?php esc_html_e('TX ID появится после того, как платёж пройдёт.', 'woo-exchange-quote-gateway'); ?></p>
  <p class="eq-tx" id="eq-txid" style="display:none;"></p>
  <p class="eq-status" id="eq-status" style="display:none;"></p>
  <p class="eq-redirect" id="eq-redirect" style="display:none;"></p>
  <p class="eq-tx" style="margin-top:1rem;"><?php esc_html_e('После подтверждения платежа вы будете перенаправлены на страницу «Заказ получен» в магазине.', 'woo-exchange-quote-gateway'); ?></p>
</div>
<script>
(function(){
  var countdownMin = <?php echo (int) $countdown_min; ?>;
  var totalSec = countdownMin * 60;
  var left = totalSec;
  var ajaxUrl = <?php echo json_encode($ajax_url); ?>;
  var orderReceivedUrl = <?php echo json_encode($order_received_url); ?>;
  var el = document.getElementById('eq-countdown');
  var statusEl = document.getElementById('eq-status');
  var redirectEl = document.getElementById('eq-redirect');

  function fmt(t) {
    var m = Math.floor(t / 60), s = t % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  var countdown = setInterval(function(){
    left--;
    if (el) el.textContent = fmt(left);
    if (left <= 0) clearInterval(countdown);
  }, 1000);

  function poll() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', ajaxUrl, true);
    xhr.onload = function(){
      if (xhr.status !== 200) { setTimeout(poll, 10000); return; }
      try {
        var r = JSON.parse(xhr.responseText);
        if (r.success && r.data && r.data.paid) {
          clearInterval(countdown);
          if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.textContent = '<?php echo esc_js(__('Платёж зачислен.', 'woo-exchange-quote-gateway')); ?>';
          }
          if (redirectEl) {
            redirectEl.style.display = 'block';
            redirectEl.textContent = '<?php echo esc_js(__('Ожидание подтверждения в сети. Переадресация на страницу заказа…', 'woo-exchange-quote-gateway')); ?>';
          }
          setTimeout(function(){ window.location.href = r.data.order_received_url || orderReceivedUrl; }, 2500);
          return;
        }
      } catch (e) {}
      setTimeout(poll, 10000);
    };
    xhr.onerror = function(){ setTimeout(poll, 10000); };
    xhr.send();
  }
  setTimeout(poll, 5000);
})();
</script>
</body></html>
        <?php
        exit;
    }

    /**
     * Адрес LTC для заказа: только из публичного ключа (Ltub).
     * Источники: фильтр (CryptoWoo и др.) → локальный HD из Ltub → опционально HD API. Резервный адрес не используется.
     */
    protected function get_ltc_address_for_order($order_id, $order) {
        $address = $this->get_ltc_address_from_cryptowoo($order_id, $order);
        if ($address !== '') {
            $this->log('LTC address from CryptoWoo: ' . $address);
            return $address;
        }
        if ($this->get_option('use_hd_wallet') === 'yes') {
            $xpub = trim($this->get_option('ltc_xpub', ''));
            if ($xpub !== '') {
                $address = $this->get_ltc_address_from_hd_local($order_id, $order);
                if ($address !== '') {
                    $this->log('LTC address from HD local (index ' . $order->get_meta('_exchange_quote_hd_index') . '): ' . $address);
                    return $address;
                }
                $address = $this->get_ltc_address_from_hd_api($order_id, $order);
                if ($address !== '') {
                    $this->log('LTC address from HD API (index ' . $order->get_meta('_exchange_quote_hd_index') . '): ' . $address);
                    return $address;
                }
                $this->log('HD derive failed for Ltub; no fallback address.');
            }
        }
        return apply_filters('woo_exchange_quote_ltc_address', '', $order_id, $order);
    }

    /**
     * Получить LTC-адрес из HD локально в плагине (без main.py): фильтр или встроенный deriver.
     * Сохраняет индекс в мету заказа и увеличивает счётчик.
     */
    protected function get_ltc_address_from_hd_local($order_id, $order) {
        $xpub = trim($this->get_option('ltc_xpub', ''));
        $next_index = (int) get_option('woo_exchange_quote_hd_next_index', 0);

        // 1) Фильтр: тема/плагин может отдать адрес (свой код или своя библиотека)
        $address = apply_filters('woo_exchange_quote_hd_derive_address', '', $xpub, $next_index);
        if (is_string($address) && $address !== '' && $this->looks_like_ltc_address($address)) {
            update_option('woo_exchange_quote_hd_next_index', $next_index + 1);
            $order->update_meta_data('_exchange_quote_hd_index', $next_index);
            return trim($address);
        }

        // 2) Встроенный deriver (чистый PHP, без внешнего API)
        if (class_exists('WC_Exchange_Quote_LTC_HD_Deriver', false)) {
            $address = WC_Exchange_Quote_LTC_HD_Deriver::derive($xpub, $next_index);
            if (is_string($address) && $address !== '' && $this->looks_like_ltc_address($address)) {
                update_option('woo_exchange_quote_hd_next_index', $next_index + 1);
                $order->update_meta_data('_exchange_quote_hd_index', $next_index);
                return trim($address);
            }
        }

        return '';
    }

    /**
     * Получить LTC-адрес из внешнего API HD derive (опционально, например main.py).
     * Сохраняет индекс в мету заказа и увеличивает счётчик.
     */
    protected function get_ltc_address_from_hd_api($order_id, $order) {
        $api_base = $this->get_option('api_base_url');
        if (empty($api_base)) {
            return '';
        }
        $next_index = (int) get_option('woo_exchange_quote_hd_next_index', 0);
        $url = rtrim($api_base, '/') . '/api/v1/hd/derive?xpub=' . rawurlencode($this->get_option('ltc_xpub', '')) . '&index=' . $next_index;
        $resp = wp_remote_get($url, array('timeout' => 15));
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            $this->log('HD derive failed: ' . (is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_body($resp)));
            return '';
        }
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($json['address'])) {
            return '';
        }
        $address = trim($json['address']);
        if (!$this->looks_like_ltc_address($address)) {
            return '';
        }
        update_option('woo_exchange_quote_hd_next_index', $next_index + 1);
        $order->update_meta_data('_exchange_quote_hd_index', $next_index);
        return $address;
    }

    /**
     * Пытается получить LTC-адрес из плагина CryptoWoo / CryptoWoo HD Wallet Add-on.
     * CryptoWoo хранит адрес в мете заказа; возможные ключи зависят от версии.
     */
    protected function get_ltc_address_from_cryptowoo($order_id, $order) {
        if (!$order_id && $order) {
            $order_id = $order->get_id();
        }
        $meta_keys = array(
            '_cryptowoo_ltc_address',
            '_payment_address_ltc',
            '_payment_address',
            '_address_ltc',
            '_deposit_address',
            'cryptowoo_ltc_address',
            'payment_address',
        );
        foreach ($meta_keys as $key) {
            $value = $order ? $order->get_meta($key) : get_post_meta($order_id, $key, true);
            if (is_string($value) && trim($value) !== '' && $this->looks_like_ltc_address($value)) {
                return trim($value);
            }
        }
        // CryptoWoo может хранить по ключу с валютой в суффиксе (например из класса CW_Order_Processing)
        if (function_exists('get_post_meta') && $order_id) {
            $all_meta = get_post_meta($order_id);
            if (is_array($all_meta)) {
                foreach ($all_meta as $k => $v) {
                    if (stripos($k, 'ltc') !== false && (stripos($k, 'address') !== false || stripos($k, 'payment') !== false) && is_array($v) && !empty($v[0])) {
                        $val = trim($v[0]);
                        if ($this->looks_like_ltc_address($val)) {
                            return $val;
                        }
                    }
                }
            }
        }
        return '';
    }

    /**
     * Проверка, что строка похожа на LTC-адрес (L-префикс или ltc1 bech32).
     */
    protected function looks_like_ltc_address($s) {
        $s = trim($s);
        if ($s === '') return false;
        return (preg_match('/^[LM3][a-km-zA-HJ-NP-Z1-9]{26,33}$/', $s) || preg_match('/^ltc1[qpa-z0-9]{39,59}$/i', $s));
    }

    /**
     * Fetch quote for order (process_payment). Calls your Quote API; the API obtains and refreshes Meld token automatically.
     */
    protected function fetch_quote($amount, $wallet_address = '') {
        $api_base = $this->get_option('api_base_url');
        if (empty($api_base)) {
            return array();
        }
        $provider_filter = array_filter(array_map('trim', explode(',', $this->get_option('provider_filter', 'REVOLUT'))));
        $url = rtrim($api_base, '/') . '/api/v1/quote';
        $body = array(
            'country_code'             => $this->get_option('country_code', 'GB'),
            'source_currency_code'     => $this->get_option('source_currency', 'GBP'),
            'destination_currency_code' => $this->get_option('destination_crypto', 'LTC'),
            'source_amount'             => $amount,
            'payment_method_type'      => 'CREDIT_DEBIT_CARD',
            'provider_filter'          => $provider_filter,
        );
        if ($wallet_address) {
            $body['wallet_address'] = $wallet_address;
        }
        $resp = wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return array();
        }
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($json['quotes'][0])) {
            return array();
        }
        $q = $json['quotes'][0];
        return array(
            'source_amount'        => (float) $q['source_amount'],
            'source_currency'      => $q['source_currency_code'],
            'destination_amount'   => (float) $q['destination_amount'],
            'destination_currency' => $q['destination_currency_code'],
        );
    }

    /**
     * Формирование ссылки на оплату: сумма (GBP и LTC при наличии), адрес LTC.
     * При нажатии в корзине/checkout пользователь перекидывается на fluidmoney.xyz (или свой URL).
     * Плагин отслеживает поступление указанной суммы LTC на этот адрес.
     */
    protected function build_payment_redirect_url($order, $amount_gbp, $ltc_address) {
        $amount_ltc = $order->get_meta('_exchange_quote_ltc_amount');
        $amount_ltc = $amount_ltc !== '' ? (float) $amount_ltc : '';

        $custom_url = $this->get_option('payment_page_url', '');
        if ($custom_url !== '') {
            $url = str_replace(
                array('{amount_gbp}', '{amount_ltc}', '{ltc_address}', '{order_id}'),
                array($amount_gbp, $amount_ltc, rawurlencode($ltc_address), $order->get_id()),
                $custom_url
            );
            return $url;
        }

        // Редирект на fluidmoney.xyz: сумма (GBP), адрес LTC (walletAddress), ожидаемая сумма LTC при наличии
        $ltc_address = is_string($ltc_address) ? trim($ltc_address) : '';
        $params = array(
            'sourceCurrencyCode'     => $this->get_option('source_currency', 'GBP'),
            'sourceAmount'           => $amount_gbp,
            'destinationCurrencyCode' => $this->get_option('destination_crypto', 'LTC'),
            'paymentMethodType'      => 'CREDIT_DEBIT_CARD',
            'countryCode'            => $this->get_option('country_code', 'GB'),
            'serviceProvider'        => 'REVOLUT',
        );
        if ($ltc_address !== '') {
            $params['walletAddress'] = $ltc_address;
        }
        if ($amount_ltc !== '') {
            $params['expectedDestinationAmount'] = $amount_ltc;
        }
        return 'https://fluidmoney.xyz/?' . http_build_query($params);
    }

    protected function log($message) {
        if ($this->get_option('enable_log') !== 'yes') {
            return;
        }
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug($message, array('source' => 'exchange-quote-gateway'));
        }
    }

    /**
     * Записать в лог сгенерированный адрес: дата, время, сумма, email, адрес.
     */
    protected function log_generated_address($order, $ltc_address) {
        $log = get_option(self::ADDRESS_LOG_OPTION, array());
        if (! is_array($log)) {
            $log = array();
        }
        $total  = (float) $order->get_total();
        $currency = $order->get_currency();
        $email = $order->get_billing_email();
        array_unshift($log, array(
            'date'    => current_time('Y-m-d'),
            'time'    => current_time('H:i:s'),
            'amount'  => $total,
            'currency' => $currency,
            'email'   => $email !== '' ? $email : '—',
            'address' => $ltc_address,
            'order_id' => $order->get_id(),
        ));
        $log = array_slice($log, 0, self::ADDRESS_LOG_MAX_ENTRIES);
        update_option(self::ADDRESS_LOG_OPTION, $log);
    }

    /**
     * Генерация HTML для поля типа address_log в настройках способа оплаты.
     * WC_Settings_API на странице шлюза вызывает именно generate_{type}_html(), а не глобальный хук.
     */
    public function generate_address_log_html($key, $data) {
        ob_start();
        $this->render_address_log_field($data);
        return ob_get_clean();
    }

    /**
     * Вывод раздела «Логи сгенерированных адресов» в настройках (таблица: дата, время, сумма, email, адрес).
     */
    public function render_address_log_field($field) {
        $log = get_option(self::ADDRESS_LOG_OPTION, array());
        if (! is_array($log)) {
            $log = array();
        }
        ?>
        <tr valign="top">
            <td colspan="2" style="padding:0;">
                <table class="widefat striped" style="margin-top:8px;max-width:900px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Дата', 'woo-exchange-quote-gateway'); ?></th>
                            <th><?php esc_html_e('Время', 'woo-exchange-quote-gateway'); ?></th>
                            <th><?php esc_html_e('Сумма', 'woo-exchange-quote-gateway'); ?></th>
                            <th><?php esc_html_e('Email', 'woo-exchange-quote-gateway'); ?></th>
                            <th><?php esc_html_e('Адрес LTC', 'woo-exchange-quote-gateway'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($log)) : ?>
                        <tr><td colspan="5"><?php esc_html_e('Пока нет записей.', 'woo-exchange-quote-gateway'); ?></td></tr>
                        <?php else : ?>
                        <?php foreach ($log as $row) : ?>
                        <tr>
                            <td><?php echo esc_html(isset($row['date']) ? $row['date'] : '—'); ?></td>
                            <td><?php echo esc_html(isset($row['time']) ? $row['time'] : '—'); ?></td>
                            <td><?php echo esc_html(isset($row['amount']) ? $row['amount'] . ' ' . (isset($row['currency']) ? $row['currency'] : '') : '—'); ?></td>
                            <td><?php echo esc_html(isset($row['email']) ? $row['email'] : '—'); ?></td>
                            <td style="word-break:break-all;"><?php echo esc_html(isset($row['address']) ? $row['address'] : '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </td>
        </tr>
        <?php
    }
}
