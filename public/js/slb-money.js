/**
 * EUR ledger amounts. Catalog uses live rate; cart/checkout use locked cart_rate.
 * window.__slbMoney = { currency, usd, rate, cart_rate, symbol, guide }
 */
(function (global) {
    'use strict';

    function config() {
        return global.__slbMoney || {
            currency: 'EUR',
            usd: false,
            rate: 1,
            cart_rate: 1,
            symbol: '€',
            guide: false
        };
    }

    function finiteRate(value, fallback) {
        var rate = Number(value);
        if (!Number.isFinite(rate) || rate <= 0) {
            rate = Number(fallback);
        }
        if (!Number.isFinite(rate) || rate <= 0) {
            return 1;
        }
        return rate;
    }

    function euros(value) {
        var n = Number(value);
        if (!Number.isFinite(n)) n = 0;
        return Math.round(n * 100) / 100;
    }

    function amount(rawEuros) {
        var n = euros(rawEuros);
        n = n * finiteRate(config().rate, 1);
        return Math.round(n * 100) / 100;
    }

    var MINUS = '\u2212';

    function signedPrefix(value, signed) {
        if (value < 0) return MINUS;
        return signed && value > 0 ? '+' : '';
    }

    function grouped(n) {
        var parts = Math.abs(Number(n) || 0).toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.join('.');
    }

    function format(rawEuros, options) {
        options = options || {};
        var cfg = config();
        var eur = euros(rawEuros);
        var value = amount(rawEuros);
        var symbol = cfg.symbol || '€';
        var prefix = signedPrefix(value, !!options.signed);
        var local = symbol + grouped(value);
        if (!cfg.guide || cfg.currency === 'EUR') {
            return prefix + local;
        }
        return prefix + local + ' (≈ €' + grouped(eur) + ')';
    }

    function formatPay(rawEuros, options) {
        options = options || {};
        var eur = euros(rawEuros);
        var sign = signedPrefix(eur, !!options.signed);
        var eurLabel = '€' + grouped(eur);
        var cfg = config();
        if (!cfg.guide || cfg.currency === 'EUR') {
            return sign + eurLabel;
        }
        var rate = finiteRate(cfg.cart_rate, cfg.rate);
        var local = Math.round(Math.abs(eur) * rate * 100) / 100;
        return sign + eurLabel + ' · about ' + (cfg.symbol || '$') + grouped(local);
    }

    global.slbFormatMoney = format;
    global.slbFormatPay = formatPay;
    global.slbMoneyAmount = amount;
})(window);
