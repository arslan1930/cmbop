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

    function format(rawEuros, options) {
        options = options || {};
        var value = amount(rawEuros);
        var symbol = config().symbol || '€';
        return signedPrefix(value, !!options.signed) + symbol + Math.abs(value).toFixed(2);
    }

    function formatPay(rawEuros, options) {
        options = options || {};
        var eur = euros(rawEuros);
        var sign = signedPrefix(eur, !!options.signed);
        var eurLabel = '€' + Math.abs(eur).toFixed(2);
        var cfg = config();
        if (!cfg.guide || cfg.currency === 'EUR') {
            return sign + eurLabel;
        }
        var rate = finiteRate(cfg.cart_rate, cfg.rate);
        var local = Math.round(Math.abs(eur) * rate * 100) / 100;
        return sign + eurLabel + ' · about ' + (cfg.symbol || '$') + local.toFixed(2);
    }

    global.slbFormatMoney = format;
    global.slbFormatPay = formatPay;
    global.slbMoneyAmount = amount;
})(window);
