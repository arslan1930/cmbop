/**
 * EUR ledger amounts. One display currency — no ≈ / about secondary text.
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
        var value = amount(rawEuros);
        var symbol = cfg.symbol || '€';
        var prefix = signedPrefix(value, !!options.signed);
        return prefix + symbol + grouped(value);
    }

    function formatPay(rawEuros, options) {
        options = options || {};
        var cfg = config();
        if (!cfg.guide || cfg.currency === 'EUR') {
            return format(rawEuros, options);
        }
        var eur = euros(rawEuros);
        var rate = finiteRate(cfg.cart_rate, cfg.rate);
        var local = Math.round(eur * rate * 100) / 100;
        var prefix = signedPrefix(local, !!options.signed);
        return prefix + (cfg.symbol || '€') + grouped(local);
    }

    function toEuros(localAmount) {
        var n = Number(localAmount);
        if (!Number.isFinite(n)) n = 0;
        var cfg = config();
        if (!cfg.guide || cfg.currency === 'EUR') {
            return Math.round(n * 100) / 100;
        }
        var rate = finiteRate(cfg.rate, 1);
        return Math.round((n / rate) * 100) / 100;
    }

    function applyFx(fx) {
        if (!fx || typeof fx !== 'object') {
            return config();
        }
        var currency = String(fx.currency || 'EUR').toUpperCase();
        var next = {
            currency: currency,
            usd: !!fx.usd || currency === 'USD',
            rate: finiteRate(fx.rate, 1),
            cart_rate: finiteRate(fx.cart_rate, fx.rate),
            symbol: currency === 'EUR' ? '€' : (fx.symbol || '€'),
            guide: currency !== 'EUR'
        };
        global.__slbMoney = next;
        return next;
    }

    global.slbFormatMoney = format;
    global.slbFormatPay = formatPay;
    global.slbMoneyAmount = amount;
    global.slbToEuros = toEuros;
    global.slbApplyMoneyFx = applyFx;
})(window);
