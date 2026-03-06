<?php
/**
 * Plugin Name: WooCommerce Exchange Quote — оплата картой (крипта LTC)
 * Description: Оплата картой с редиректом на страницу оплаты LTC.
 * Version: 1.0.15
 * Author: Degree Team
 * Author URI: https://github.com/propafinder/woo-exchange-quote-gateway
 * Text Domain: woo-exchange-quote-gateway
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.x
 * Update URI: https://github.com/propafinder/woo-exchange-quote-gateway/
 */

defined('ABSPATH') || exit;

/** URL репозитория на GitHub (релизы: /releases/tag/vX.Y.Z) */
define('WOO_EXCHANGE_QUOTE_GATEWAY_GITHUB_REPO', 'https://github.com/propafinder/woo-exchange-quote-gateway');

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

// Ссылка на релиз и «Проверить обновления» в строке плагина (как у плагинов с wordpress.org — обновление без перехода на GitHub)
add_filter('plugin_row_meta', 'woo_exchange_quote_plugin_row_meta', 10, 4);
function woo_exchange_quote_plugin_row_meta($plugin_meta, $plugin_file, $plugin_data, $status) {
    $basename = 'woo-exchange-quote-gateway/woo-exchange-quote-gateway.php';
    if ($plugin_file !== $basename) {
        return $plugin_meta;
    }
    $version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '';
    if ($version !== '') {
        $release_url = WOO_EXCHANGE_QUOTE_GATEWAY_GITHUB_REPO . '/releases/tag/v' . $version;
        $plugin_meta[] = '<a href="' . esc_url($release_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Release', 'woo-exchange-quote-gateway') . ' v' . esc_html($version) . '</a>';
    }
    $check_url = wp_nonce_url(admin_url('plugins.php?eq_check_updates=1'), 'eq_check_updates');
    $plugin_meta[] = '<a href="' . esc_url($check_url) . '">' . esc_html__('Проверить обновления', 'woo-exchange-quote-gateway') . '</a>';
    return $plugin_meta;
}

add_action('admin_init', 'woo_exchange_quote_clear_update_cache');
function woo_exchange_quote_clear_update_cache() {
    if (! isset($_GET['eq_check_updates']) || ! current_user_can('update_plugins')) {
        return;
    }
    if (! wp_verify_nonce(isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '', 'eq_check_updates')) {
        return;
    }
    delete_site_transient('woo_eq_gateway_github_release');
    delete_site_transient('update_plugins');
    wp_safe_redirect(admin_url('update-core.php'));
    exit;
}

add_action('admin_enqueue_scripts', 'woo_exchange_quote_admin_plugins_script');
function woo_exchange_quote_admin_plugins_script($hook) {
    if ($hook !== 'plugins.php') {
        return;
    }
    $plugin_data = get_plugin_data(__DIR__ . '/woo-exchange-quote-gateway.php', false, false);
    $version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '';
    if ($version === '') {
        return;
    }
    $release_url = WOO_EXCHANGE_QUOTE_GATEWAY_GITHUB_REPO . '/releases/tag/v' . $version;
    wp_add_inline_script('jquery', sprintf(
        '(function(){var u=%s;setTimeout(function(){jQuery("tr:has(a[href*=\"woo-exchange-quote-gateway/woo-exchange-quote-gateway.php\"])").each(function(){var $row=jQuery(this),$cells=$row.children("td");if($cells.length<2)return;var $last=$cells.last();var html=$last.html()||"";if(html&&html.indexOf("<a ")===-1){$last.html(\'<a href="\'+u+\'" target="_blank" rel="noopener noreferrer">\'+html.trim()+\'</a>\');}});},100);})();',
        json_encode($release_url)
    ));
}
