<?php
/**
 * WooCommerce Payment Gateway: оплата «картой» с курсом обмена и редиректом на крипто-оплату LTC.
 * Котировка берётся из Exchange Quote API (парсер, Revolut). На странице оплаты подставляются сумма в GBP и адрес LTC.
 */

defined('ABSPATH') || exit;

class WC_Gateway_Exchange_Quote extends WC_Payment_Gateway {

    const PAYMENT_METHOD_ID = 'exchange_quote';

    public function __construct() {
        $this->id                 = self::PAYMENT_METHOD_ID;
        $this->method_title       = __('Exchange Quote — оплата картой (LTC)', 'woo-exchange-quote-gateway');
        $this->method_description = __('Клиент видит способ «картой», курс из парсера (Revolut), сумму в GBP и LTC. После checkout — редирект на страницу оплаты с подставленными суммой и адресом LTC. Заказ в статусе pending до подтверждения крипты.', 'woo-exchange-quote-gateway');
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
                'title'       => __('Название способа оплаты', 'woo-exchange-quote-gateway'),
                'type'        => 'text',
                'description' => __('Текст, который видит клиент в корзине и на checkout (например: «Оплата картой — курс Revolut»).', 'woo-exchange-quote-gateway'),
                'default'     => __('Оплата картой (курс Revolut)', 'woo-exchange-quote-gateway'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Описание', 'woo-exchange-quote-gateway'),
                'type'        => 'textarea',
                'description' => __('Краткое описание под способом оплаты.', 'woo-exchange-quote-gateway'),
                'default'     => __('Оплата картой: вы платите в GBP по курсу Revolut, мы получаем LTC на кошелёк магазина.', 'woo-exchange-quote-gateway'),
                'desc_tip'    => true,
            ),
            'meld_session_token' => array(
                'title'       => __('Токен сессии Meld (рекомендуется)', 'woo-exchange-quote-gateway'),
                'type'        => 'password',
                'description' => __('JWT из fluidmoney.xyz (вкладка Сеть → x-crypto-session-token). Котировки запрашиваются напрямую из плагина в Meld API, Python не нужен.', 'woo-exchange-quote-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'meld_api_url' => array(
                'title'       => __('URL Meld API (если другой)', 'woo-exchange-quote-gateway'),
                'type'        => 'url',
                'description' => __('По умолчанию: https://meldcrypto.com/_api/crypto/session/quote. Оставьте пустым для стандартного.', 'woo-exchange-quote-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'api_base_url' => array(
                'title'       => __('URL своего API котировок (опционально)', 'woo-exchange-quote-gateway'),
                'type'        => 'url',
                'description' => __('Если не используете Meld напрямую: базовый URL вашего API (ответ как у main.py). Без слэша в конце.', 'woo-exchange-quote-gateway'),
                'default'     => '',
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
                'label'       => __('Генерировать LTC-адрес из расширенного публичного ключа (Ltub). Каждый заказ — свой адрес.', 'woo-exchange-quote-gateway'),
                'default'     => 'no',
                'description' => __('Если включено, адрес берётся из API /api/v1/hd/derive по Ltub и индексу. Иначе — один адрес из поля ниже или CryptoWoo.', 'woo-exchange-quote-gateway'),
            ),
            'ltc_xpub' => array(
                'title'       => __('LTC расширенный публичный ключ (Ltub)', 'woo-exchange-quote-gateway'),
                'type'        => 'text',
                'description' => __('Ltub... (Litecoin mainnet). Используется только при включённом HD Wallet. Можно менять в настройках.', 'woo-exchange-quote-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'ltc_wallet_address' => array(
                'title'       => __('LTC адрес кошелька магазина (резерв)', 'woo-exchange-quote-gateway'),
                'type'        => 'text',
                'description' => __('Один адрес, если HD выключен и нет CryptoWoo. Или резерв, если API HD недоступен.', 'woo-exchange-quote-gateway'),
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
                'loading' => __('Загрузка курса…', 'woo-exchange-quote-gateway'),
                'error'   => __('Не удалось получить курс. Проверьте сумму и попробуйте снова.', 'woo-exchange-quote-gateway'),
                'summary' => __('Сумма к оплате: %1$s %2$s. По текущему курсу (Revolut): %3$s %4$s.', 'woo-exchange-quote-gateway'),
            ),
        ));
    }

    /**
     * AJAX: получить котировку для суммы заказа (вызывается с checkout при смене способа оплаты или суммы).
     */
    public function ajax_get_quote() {
        check_ajax_referer('woo_exchange_quote_nonce', 'nonce');

        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        if ($amount <= 0) {
            wp_send_json_error(array('message' => __('Некорректная сумма.', 'woo-exchange-quote-gateway')));
        }

        $provider_filter = array_filter(array_map('trim', explode(',', $this->get_option('provider_filter', 'REVOLUT'))));

        if (trim($this->get_option('meld_session_token', '')) !== '') {
            $result = $this->fetch_quote_via_meld($amount, '', $provider_filter);
            if (!empty($result['quotes'][0])) {
                $best = $result['quotes'][0];
                wp_send_json_success(array(
                    'source_amount'        => (float) $best['source_amount'],
                    'source_currency'      => $best['source_currency_code'],
                    'destination_amount'   => (float) $best['destination_amount'],
                    'destination_currency' => $best['destination_currency_code'],
                    'exchange_rate'       => isset($best['exchange_rate']) ? (float) $best['exchange_rate'] : 0,
                    'provider'             => isset($best['service_provider']) ? $best['service_provider'] : '',
                ));
            }
            if (!empty($result['error'])) {
                wp_send_json_error(array('message' => $result['error']));
            }
        }

        $api_base = $this->get_option('api_base_url');
        if (empty($api_base)) {
            wp_send_json_error(array('message' => __('Задайте токен Meld или URL своего API котировок.', 'woo-exchange-quote-gateway')));
        }

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

        $this->log('Quote request: ' . $url . ' body=' . wp_json_encode($body));
        if (is_wp_error($resp)) {
            $this->log('Quote error: ' . $resp->get_error_message());
            wp_send_json_error(array('message' => $resp->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($resp);
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        $this->log('Quote response code=' . $code);

        if ($code !== 200 || empty($json['success']) || empty($json['quotes'][0])) {
            $msg = isset($json['error']) ? $json['error'] : __('Котировки недоступны.', 'woo-exchange-quote-gateway');
            wp_send_json_error(array('message' => $msg));
        }

        $best = $json['quotes'][0];
        wp_send_json_success(array(
            'source_amount'        => (float) $best['source_amount'],
            'source_currency'      => $best['source_currency_code'],
            'destination_amount'   => (float) $best['destination_amount'],
            'destination_currency' => $best['destination_currency_code'],
            'exchange_rate'       => isset($best['exchange_rate']) ? (float) $best['exchange_rate'] : 0,
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
        $order->save();

        // Статус pending до подтверждения крипты
        $order->update_status('pending', __('Ожидание оплаты (крипто). Клиент перенаправлен на страницу оплаты.', 'woo-exchange-quote-gateway'));

        $redirect_url = $this->build_payment_redirect_url($order, $total, $ltc_address);
        $this->log('Redirect order ' . $order_id . ' to ' . $redirect_url);

        return array(
            'result'   => 'success',
            'redirect' => $redirect_url,
        );
    }

    /**
     * Адрес LTC для заказа: CryptoWoo → HD Wallet (Ltub через API) → один адрес из настроек → фильтр.
     * Передаётся в API котировок (wallet_address) и в URL редиректа на оплату.
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
                // 1) Локальный вывод в плагине (без main.py)
                $address = $this->get_ltc_address_from_hd_local($order_id, $order);
                if ($address !== '') {
                    $this->log('LTC address from HD local (index ' . $order->get_meta('_exchange_quote_hd_index') . '): ' . $address);
                    return $address;
                }
                // 2) Опционально: внешний API (например main.py) — только если нужна интеграция с ним
                $address = $this->get_ltc_address_from_hd_api($order_id, $order);
                if ($address !== '') {
                    $this->log('LTC address from HD API (index ' . $order->get_meta('_exchange_quote_hd_index') . '): ' . $address);
                    return $address;
                }
            }
        }
        $address = $this->get_option('ltc_wallet_address', '');
        return apply_filters('woo_exchange_quote_ltc_address', $address, $order_id, $order);
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
     * Запрос котировки: сначала Meld API из PHP (если задан токен), иначе — свой API (api_base_url).
     * Формат ответа единый: source_amount, source_currency, destination_amount, destination_currency.
     */
    protected function fetch_quote($amount, $wallet_address = '') {
        $provider_filter = array_filter(array_map('trim', explode(',', $this->get_option('provider_filter', 'REVOLUT'))));

        if (trim($this->get_option('meld_session_token', '')) !== '') {
            $result = $this->fetch_quote_via_meld($amount, $wallet_address, $provider_filter);
            if (!empty($result['quotes'][0])) {
                $q = $result['quotes'][0];
                return array(
                    'source_amount'        => (float) $q['source_amount'],
                    'source_currency'      => $q['source_currency_code'],
                    'destination_amount'   => (float) $q['destination_amount'],
                    'destination_currency' => $q['destination_currency_code'],
                );
            }
        }

        $api_base = $this->get_option('api_base_url');
        if (empty($api_base)) {
            return array();
        }
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
     * Запрос котировок напрямую в Meld API (PHP, без Python).
     * Возвращает массив [ 'success' => bool, 'quotes' => array, 'error' => string ].
     */
    protected function fetch_quote_via_meld($amount, $wallet_address, $provider_filter = array()) {
        $token = trim($this->get_option('meld_session_token', ''));
        if ($token === '') {
            return array('success' => false, 'quotes' => array(), 'error' => '');
        }
        $url = trim($this->get_option('meld_api_url', ''));
        $result = WC_Meld_Quotes::fetch_quotes(array(
            'country_code'             => $this->get_option('country_code', 'GB'),
            'source_currency_code'     => $this->get_option('source_currency', 'GBP'),
            'destination_currency_code' => $this->get_option('destination_crypto', 'LTC'),
            'source_amount'            => $amount,
            'payment_method_type'      => 'CREDIT_DEBIT_CARD',
            'wallet_address'           => $wallet_address,
            'session_token'            => $token,
            'provider_filter'          => $provider_filter,
            'meld_api_url'             => $url !== '' ? $url : null,
        ));
        $this->log('Meld quote: success=' . ($result['success'] ? '1' : '0') . ' quotes=' . count($result['quotes']));
        return $result;
    }

    /**
     * Формирование ссылки на оплату: сумма (GBP и LTC из ответа Meld), адрес LTC.
     * При нажатии в корзине/checkout пользователь перекидывается на fluidmoney.xyz (или свой URL).
     * Плагин далее отслеживает поступление указанной суммы LTC (из Meld API) на этот адрес.
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

        // Редирект на fluidmoney.xyz: сумма к оплате (GBP), адрес LTC, ожидаемая сумма LTC (из Meld)
        $params = array(
            'sourceCurrencyCode'     => $this->get_option('source_currency', 'GBP'),
            'sourceAmount'           => $amount_gbp,
            'destinationCurrencyCode' => $this->get_option('destination_crypto', 'LTC'),
            'paymentMethodType'      => 'CREDIT_DEBIT_CARD',
            'countryCode'            => $this->get_option('country_code', 'GB'),
            'serviceProvider'        => 'REVOLUT',
        );
        if ($ltc_address) {
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
}
