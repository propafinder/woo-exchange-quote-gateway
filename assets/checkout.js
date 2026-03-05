/**
 * Checkout: при выборе способа оплаты Exchange Quote запрашивает котировку и показывает сумму в GBP и LTC.
 */
(function ($) {
    'use strict';

    function getOrderTotal() {
        // Сумма из блока итого WooCommerce (разные темы по-разному)
        var totalEl = $('.order-total .woocommerce-Price-amount bdi, .order-total .amount .woocommerce-Price-amount');
        if (totalEl.length) {
            var text = totalEl.first().text().replace(/[^\d.,]/g, '').replace(',', '.');
            var num = parseFloat(text);
            if (!isNaN(num)) return num;
        }
        // Альтернатива: из данных checkout
        if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.order_total) {
            return parseFloat(wc_checkout_params.order_total);
        }
        return 0;
    }

    function fetchQuote() {
        var total = getOrderTotal();
        var $summary = $('#woo-exchange-quote-summary');
        var $loading = $summary.find('.woo-eq-loading');
        var $result = $summary.find('.woo-eq-result');
        var $error = $summary.find('.woo-eq-error');

        $result.hide();
        $error.hide();

        if (total <= 0) {
            $loading.hide();
            $error.text(wooExchangeQuote.strings.error).show();
            return;
        }

        $summary.show();
        $loading.show();

        $.ajax({
            url: wooExchangeQuote.ajax_url,
            type: 'POST',
            data: {
                action: 'woo_exchange_quote_get_quote',
                nonce: wooExchangeQuote.nonce || '',
                amount: total
            },
            success: function (data) {
                $loading.hide();
                if (data.success && data.data) {
                    var d = data.data;
                    if (d.no_quote && wooExchangeQuote.strings.no_quote) {
                        $result.html(wooExchangeQuote.strings.no_quote).show();
                        $error.hide();
                    } else if (d.destination_amount != null) {
                        var msg = wooExchangeQuote.strings.summary
                            .replace('%1$s', d.source_amount)
                            .replace('%2$s', d.source_currency)
                            .replace('%3$s', d.destination_amount.toFixed(4))
                            .replace('%4$s', d.destination_currency);
                        $result.html(msg).show();
                        $error.hide();
                    } else {
                        $error.text(d.message || wooExchangeQuote.strings.error).show();
                    }
                } else {
                    $error.text(data.data && data.data.message ? data.data.message : wooExchangeQuote.strings.error).show();
                }
            },
            error: function (xhr, status, err) {
                $loading.hide();
                $error.text(wooExchangeQuote.strings.error + ' ' + (err || status)).show();
            }
        });
    }

    function maybeFetchQuote() {
        var method = $('input[name="payment_method"]:checked').val();
        if (method === 'exchange_quote') {
            fetchQuote();
        } else {
            $('#woo-exchange-quote-summary').hide();
        }
    }

    $(function () {
        if (typeof wooExchangeQuote === 'undefined') return;

        // Nonce для AJAX
        if (!wooExchangeQuote.nonce && typeof wc_checkout_params !== 'undefined' && wc_checkout_params.woo_exchange_quote_nonce) {
            wooExchangeQuote.nonce = wc_checkout_params.woo_exchange_quote_nonce;
        }

        $(document.body).on('payment_method_selected', maybeFetchQuote);
        $(document.body).on('updated_checkout', function () {
            if ($('input[name="payment_method"]:checked').val() === 'exchange_quote') {
                fetchQuote();
            }
        });

        if ($('input[name="payment_method"]:checked').val() === 'exchange_quote') {
            fetchQuote();
        }
    });
})(jQuery);
