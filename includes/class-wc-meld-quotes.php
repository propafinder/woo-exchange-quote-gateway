<?php
/**
 * Запросы и ответы Meld API для котировок (аналог main.py на PHP).
 * Вызов Meld напрямую из плагина, без Python.
 *
 * @see https://meldcrypto.com — API session quote
 */

defined('ABSPATH') || exit;

class WC_Meld_Quotes {

    const MELD_API_URL = 'https://meldcrypto.com/_api/crypto/session/quote';

    /**
     * Запрос котировок к Meld API (то же тело и заголовки, что в main.py).
     *
     * @param array $args country_code, source_currency_code, destination_currency_code, source_amount, payment_method_type, wallet_address (optional), session_token
     * @return array { success: bool, quotes: array, error?: string } — унифицированный ответ для плагина
     */
    public static function fetch_quotes($args) {
        $defaults = array(
            'country_code'             => 'GB',
            'source_currency_code'     => 'GBP',
            'destination_currency_code' => 'LTC',
            'source_amount'            => 0,
            'payment_method_type'      => 'CREDIT_DEBIT_CARD',
            'wallet_address'           => '',
            'session_token'            => '',
        );
        $params = wp_parse_args($args, $defaults);

        if (empty($params['session_token'])) {
            return array('success' => false, 'quotes' => array(), 'error' => __('Токен Meld не задан.', 'woo-exchange-quote-gateway'));
        }

        if ((float) $params['source_amount'] <= 0) {
            return array('success' => false, 'quotes' => array(), 'error' => __('Сумма должна быть больше 0.', 'woo-exchange-quote-gateway'));
        }

        $payload = array(
            'countryCode'             => strtoupper($params['country_code']),
            'sourceCurrencyCode'      => strtoupper($params['source_currency_code']),
            'destinationCurrencyCode' => strtoupper($params['destination_currency_code']),
            'sourceAmount'            => (float) $params['source_amount'],
            'paymentMethodType'       => $params['payment_method_type'],
        );
        if (!empty($params['wallet_address'])) {
            $payload['walletAddress'] = $params['wallet_address'];
        }

        $headers = array(
            'Accept'                   => 'application/json, text/plain, */*',
            'Accept-Language'           => 'en-GB,en;q=0.9',
            'Content-Type'              => 'application/json',
            'Origin'                    => 'https://fluidmoney.xyz',
            'Referer'                   => 'https://fluidmoney.xyz/',
            'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'x-crypto-session-token'    => $params['session_token'],
        );

        $url = isset($args['meld_api_url']) && $args['meld_api_url'] !== '' ? $args['meld_api_url'] : self::MELD_API_URL;

        $resp = wp_remote_post($url, array(
            'timeout' => 30,
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($resp)) {
            return array('success' => false, 'quotes' => array(), 'error' => $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if ($code === 401) {
            return array('success' => false, 'quotes' => array(), 'error' => __('Токен Meld истёк или неверен. Обновите токен в настройках.', 'woo-exchange-quote-gateway'));
        }

        if ($code !== 200 || !is_array($json)) {
            $msg = is_array($json) && !empty($json['message']) ? $json['message'] : __('Ответ Meld API не получен.', 'woo-exchange-quote-gateway');
            return array('success' => false, 'quotes' => array(), 'error' => $msg);
        }

        $raw_quotes = isset($json['quotes']) && is_array($json['quotes']) ? $json['quotes'] : array();
        if (empty($raw_quotes)) {
            $msg = isset($json['message']) ? $json['message'] : __('Нет котировок от Meld.', 'woo-exchange-quote-gateway');
            return array('success' => false, 'quotes' => array(), 'error' => $msg);
        }

        $quotes = self::parse_quotes($raw_quotes);

        if (!empty($params['provider_filter']) && is_array($params['provider_filter'])) {
            $normalized = array_map('strtoupper', array_map('trim', $params['provider_filter']));
            $normalized = array_filter($normalized);
            if (!empty($normalized)) {
                $quotes = array_filter($quotes, function ($q) use ($normalized) {
                    $provider = isset($q['service_provider']) ? strtoupper($q['service_provider']) : '';
                    return in_array($provider, $normalized, true);
                });
                $quotes = array_values($quotes);
            }
        }

        usort($quotes, function ($a, $b) {
            $da = isset($a['destination_amount']) ? (float) $a['destination_amount'] : 0;
            $db = isset($b['destination_amount']) ? (float) $b['destination_amount'] : 0;
            return $db <=> $da;
        });

        return array('success' => true, 'quotes' => $quotes);
    }

    /**
     * Приведение сырого ответа Meld к формату, как в main.py (QuoteItem).
     */
    protected static function parse_quotes($raw_quotes) {
        $out = array();
        foreach ($raw_quotes as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $out[] = array(
                'service_provider'           => isset($raw['serviceProvider']) ? $raw['serviceProvider'] : 'UNKNOWN',
                'transaction_type'          => isset($raw['transactionType']) ? $raw['transactionType'] : 'CRYPTO_PURCHASE',
                'source_amount'             => isset($raw['sourceAmount']) ? (float) $raw['sourceAmount'] : 0,
                'source_amount_without_fees' => isset($raw['sourceAmountWithoutFees']) ? (float) $raw['sourceAmountWithoutFees'] : null,
                'source_currency_code'       => isset($raw['sourceCurrencyCode']) ? $raw['sourceCurrencyCode'] : '',
                'destination_amount'        => isset($raw['destinationAmount']) ? (float) $raw['destinationAmount'] : 0,
                'destination_currency_code' => isset($raw['destinationCurrencyCode']) ? $raw['destinationCurrencyCode'] : '',
                'exchange_rate'             => isset($raw['exchangeRate']) ? (float) $raw['exchangeRate'] : 0,
                'total_fee'                 => isset($raw['totalFee']) ? (float) $raw['totalFee'] : null,
                'transaction_fee'           => isset($raw['transactionFee']) ? (float) $raw['transactionFee'] : null,
                'network_fee'               => isset($raw['networkFee']) ? $raw['networkFee'] : null,
                'partner_fee'               => isset($raw['partnerFee']) ? (float) $raw['partnerFee'] : null,
                'payment_method_type'       => isset($raw['paymentMethodType']) ? $raw['paymentMethodType'] : '',
                'country_code'              => isset($raw['countryCode']) ? $raw['countryCode'] : '',
                'ramp_score'                => isset($raw['rampScore']) ? (float) $raw['rampScore'] : null,
            );
        }
        return $out;
    }
}
