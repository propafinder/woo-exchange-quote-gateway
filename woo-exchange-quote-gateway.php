<?php
/**
 * Plugin Name: WooCommerce Exchange Quote — оплата картой (крипта LTC)
 * Description: Способ оплаты «картой»: курс обмена из парсера (Revolut), сумма в GBP и LTC из котировки. Редирект на страницу оплаты с подставленными суммой и адресом LTC. Статус заказа — pending до подтверждения крипты.
 * Version: 1.0.0
 * Author: Exchange Quote API
 * Text Domain: woo-exchange-quote-gateway
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.x
 * Update URI: https://github.com/propafinder/woo-exchange-quote-gateway/
 */

defined('ABSPATH') || exit;

// Обновления из GitHub (Update URI + update_plugins_github.com)
require_once __DIR__ . '/includes/class-wc-exchange-quote-updater.php';
WC_Exchange_Quote_Updater::init();

// Регистрация шлюза при загрузке WooCommerce
add_action('plugins_loaded', 'woo_exchange_quote_gateway_init', 11);

function woo_exchange_quote_gateway_init() {
    if (!class_exists('WooCommerce')) {
        return;
    }

    require_once __DIR__ . '/includes/class-wc-gateway-exchange-quote.php';
    require_once __DIR__ . '/includes/class-wc-exchange-quote-verifier.php';
    require_once __DIR__ . '/includes/class-wc-ltc-hd-deriver.php';

    add_filter('woocommerce_payment_gateways', function ($methods) {
        $methods[] = 'WC_Gateway_Exchange_Quote';
        return $methods;
    });

    WC_Exchange_Quote_Verifier::init();
}

// Мета-бокс с адресом LTC для заказа (если Woo crypto передаёт адреса)
add_action('add_meta_boxes', function () {
    add_meta_box(
        'woo_exchange_quote_ltc_meta',
        __('LTC / Crypto payment', 'woo-exchange-quote-gateway'),
        'woo_exchange_quote_render_order_ltc_meta',
        'shop_order',
        'side'
    );
});

function woo_exchange_quote_render_order_ltc_meta($post) {
    $order_id = $post->ID;
    $address  = get_post_meta($order_id, '_exchange_quote_ltc_address', true);
    $ltc_amt  = get_post_meta($order_id, '_exchange_quote_ltc_amount', true);
    if ($address) {
        echo '<p><strong>LTC address:</strong><br><code style="word-break:break-all">' . esc_html($address) . '</code></p>';
    }
    if ($ltc_amt !== '') {
        echo '<p><strong>LTC amount:</strong> ' . esc_html($ltc_amt) . '</p>';
    }
    if (!$address && $ltc_amt === '') {
        echo '<p>' . esc_html__('No crypto data for this order.', 'woo-exchange-quote-gateway') . '</p>';
    }
}
