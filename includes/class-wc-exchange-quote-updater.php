<?php
/**
 * GitHub-based updates (WordPress 5.8+: Update URI + update_plugins_{hostname}).
 * Runs only during update checks (wp-admin or cron), not on frontend/checkout.
 *
 * @package WooCommerce_Exchange_Quote_Gateway
 */

defined('ABSPATH') || exit;

class WC_Exchange_Quote_Updater {

	const GITHUB_API_URL = 'https://api.github.com/repos/propafinder/woo-exchange-quote-gateway/releases/latest';
	const PLUGIN_FILE    = 'woo-exchange-quote-gateway/woo-exchange-quote-gateway.php';
	const PLUGIN_SLUG    = 'woo-exchange-quote-gateway';
	const UPDATE_URI     = 'https://github.com/propafinder/woo-exchange-quote-gateway/';
	const CACHE_KEY      = 'woo_eq_gateway_github_release';
	const CACHE_TTL      = 3600; // 1 hour (delete transient to force refresh: delete_site_transient('woo_eq_gateway_github_release'))

	/**
	 * Register update filters (only for this plugin).
	 */
	public static function init() {
		add_filter('update_plugins_github.com', array( __CLASS__, 'filter_update_plugins' ), 10, 4);
		add_filter('plugins_api', array( __CLASS__, 'filter_plugins_api' ), 10, 3);
		add_filter('pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_into_update_plugins' ), 10, 2);
	}

	/**
	 * Fetch latest release from GitHub (cached).
	 *
	 * @return array|null Release data or null on error.
	 */
	public static function get_latest_release() {
		$cached = get_site_transient(self::CACHE_KEY);
		if (is_array($cached) && ! empty($cached['tag_name'])) {
			return $cached;
		}

		$response = wp_remote_get(self::GITHUB_API_URL, array(
			'timeout' => 10,
			'headers' => array(
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'WooCommerce-Exchange-Quote-Gateway-Plugin',
			),
		));

		if (is_wp_error($response)) {
			return null;
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			return null;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		if (! is_array($data) || empty($data['tag_name'])) {
			return null;
		}

		set_site_transient(self::CACHE_KEY, $data, self::CACHE_TTL);
		return $data;
	}

	/**
	 * Filter update_plugins_{hostname}: provide update data from GitHub.
	 * Return only array or false, never void.
	 *
	 * @param array|false $update      Current update data.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file path.
	 * @param array       $locales     Locales.
	 * @return array|false
	 */
	public static function filter_update_plugins($update, $plugin_data, $plugin_file, $locales) {
		if (! self::is_our_plugin($plugin_file, $plugin_data)) {
			return $update;
		}
		if (! empty($update)) {
			return $update;
		}

		$release = self::get_latest_release();
		if (! $release) {
			return $update;
		}

		$new_version = self::normalize_version($release['tag_name']);
		$current     = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0';
		if (! version_compare($current, $new_version, '<')) {
			return false;
		}

		$package = self::get_release_package_url($release);
		if (! $package) {
			return $update;
		}

		return self::build_update_object($new_version, $release, $package, $plugin_file);
	}

	/**
	 * Inject our update into transient update_plugins (fallback when hostname hook is not used).
	 *
	 * @param object $value    update_plugins transient value.
	 * @param string $transient Transient name.
	 * @return object
	 */
	public static function inject_into_update_plugins($value, $transient) {
		if ($transient !== 'update_plugins' || ! is_object($value) || ! isset($value->response)) {
			return $value;
		}
		$release = self::get_latest_release();
		if (! $release) {
			return $value;
		}
		$new_version = self::normalize_version($release['tag_name']);
		$package     = self::get_release_package_url($release);
		if (! $package) {
			return $value;
		}
		$plugin_file = self::get_our_plugin_file();
		if (! $plugin_file) {
			return $value;
		}
		if (! function_exists('get_plugin_data')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
		$current     = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0';
		if (! version_compare($current, $new_version, '<')) {
			return $value;
		}
		$value->response[ $plugin_file ] = self::build_update_object($new_version, $release, $package, $plugin_file);
		return $value;
	}

	/**
	 * Whether this is our plugin (by path or headers).
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param array  $plugin_data Plugin headers.
	 * @return bool
	 */
	private static function is_our_plugin($plugin_file, $plugin_data) {
		$normalized = is_string($plugin_file) ? str_replace('\\', '/', $plugin_file) : '';
		if ($normalized === self::PLUGIN_FILE) {
			return true;
		}
		$name = isset($plugin_data['Name']) ? $plugin_data['Name'] : '';
		if ($name === 'WooCommerce Exchange Quote — оплата картой (крипта LTC)') {
			return true;
		}
		$uri = isset($plugin_data['Update URI']) ? trim((string) $plugin_data['Update URI']) : '';
		return ($uri !== '' && strpos($uri, 'github.com/propafinder/woo-exchange-quote-gateway') !== false);
	}

	/**
	 * Our plugin file (folder/file.php) from get_plugins().
	 *
	 * @return string|null
	 */
	private static function get_our_plugin_file() {
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		foreach ($all as $file => $data) {
			if (! empty($data['Name']) && $data['Name'] === 'WooCommerce Exchange Quote — оплата картой (крипта LTC)') {
				return $file;
			}
			$uri = isset($data['Update URI']) ? trim((string) $data['Update URI']) : '';
			if ($uri !== '' && strpos($uri, 'github.com/propafinder/woo-exchange-quote-gateway') !== false) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Build update object for WordPress (id must match Update URI).
	 *
	 * @param string $new_version New version string.
	 * @param array  $release     GitHub release data.
	 * @param string $package     ZIP download URL.
	 * @param string $plugin_file Plugin file path.
	 * @return object
	 */
	private static function build_update_object($new_version, $release, $package, $plugin_file) {
		// WordPress expects: id (Update URI), slug, plugin, version, new_version, url, package (zip URL)
		return (object) array(
			'id'           => self::UPDATE_URI,
			'slug'         => self::PLUGIN_SLUG,
			'plugin'       => $plugin_file,
			'version'      => $new_version,
			'new_version'  => $new_version,
			'url'          => isset($release['html_url']) ? $release['html_url'] : 'https://github.com/propafinder/woo-exchange-quote-gateway',
			'package'      => $package,
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
			'requires'     => '5.8',
			'tested'       => '6.7',
			'requires_php' => '7.4',
		);
	}

	/**
	 * Filter plugins_api: "View details" / plugin information screen.
	 *
	 * @param object|false $result API result.
	 * @param string       $action Action (plugin_information etc).
	 * @param object       $args   Request args.
	 * @return object|false
	 */
	public static function filter_plugins_api($result, $action, $args) {
		if ($action !== 'plugin_information') {
			return $result;
		}
		$slug = isset($args->slug) ? $args->slug : '';
		if ($slug !== self::PLUGIN_SLUG) {
			return $result;
		}

		$release = self::get_latest_release();
		if (! $release) {
			return $result;
		}

		$version = self::normalize_version($release['tag_name']);
		$package = self::get_release_package_url($release);
		$repo_url = 'https://github.com/propafinder/woo-exchange-quote-gateway';

		$info = (object) array(
			'name'          => 'WooCommerce Exchange Quote — оплата картой (крипта LTC)',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => $version,
			'author'        => 'by <a href="' . esc_url($repo_url) . '">Exchange Quote API</a>',
			'homepage'      => $repo_url,
			'download_link' => $package,
			'last_updated'  => isset($release['published_at']) ? $release['published_at'] : '',
			'sections'      => array(
				'description' => 'Card payment with Revolut rate, redirect to Fluid, HD LTC from Ltub, payment verification. Updates from GitHub.',
				'changelog'   => isset($release['body']) ? $release['body'] : '',
			),
			'requires'      => '5.8',
			'tested'        => '6.7',
			'requires_php'  => '7.4',
		);

		return $info;
	}

	/**
	 * Normalize version from tag (strip v prefix).
	 *
	 * @param string $tag_name e.g. v1.0.2.
	 * @return string
	 */
	private static function normalize_version($tag_name) {
		return is_string($tag_name) ? ltrim($tag_name, 'v') : '0';
	}

	/**
	 * First .zip asset URL from release.
	 *
	 * @param array $release GitHub API release.
	 * @return string|null
	 */
	private static function get_release_package_url($release) {
		if (empty($release['assets']) || ! is_array($release['assets'])) {
			return null;
		}
		foreach ($release['assets'] as $asset) {
			if (! empty($asset['browser_download_url'])) {
				$url = $asset['browser_download_url'];
				if (substr(strtolower($url), -4) === '.zip') {
					return $url;
				}
			}
		}
		return null;
	}
}
