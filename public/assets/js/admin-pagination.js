/**
 * Shared pagination renderer for the JS-driven admin tables.
 *
 * Orders, Payments, and Withdrawals each shipped their own copy of this, with
 * different markup, different disabled handling, and different link semantics.
 * One renderer keeps them consistent and keeps the keyboard/screen-reader
 * behaviour in a single place.
 *
 * Usage:
 *   renderAdminPagination(json.pagination, {
 *       links: '#paginationLinks',
 *       info: '#paginationInfo',
 *       label: 'payments',
 *       onNavigate: (page) => loadPayments(page),
 *   });
 *
 * `pagination` accepts Laravel's paginator shape: current_page, last_page,
 * total, and optionally from/to.
 */
(function (global) {
    'use strict';

    var BOUND = '__adminPaginationBound';

    function el(target) {
        if (!target) return null;
        return typeof target === 'string' ? document.querySelector(target) : target;
    }

    function pageWindow(current, last, span) {
        var start = Math.max(1, current - span);
        var end = Math.min(last, current + span);
        var pages = [];
        for (var i = start; i <= end; i++) {
            pages.push(i);
        }
        return pages;
    }

    function item(inner, classes) {
        return '<li class="page-item' + (classes ? ' ' + classes : '') + '">' + inner + '</li>';
    }

    function button(page, text, ariaLabel) {
        return '<button type="button" class="page-link" data-page="' + page + '"'
            + (ariaLabel ? ' aria-label="' + ariaLabel + '"' : '') + '>' + text + '</button>';
    }

    function disabled(text) {
        return '<span class="page-link" aria-disabled="true">' + text + '</span>';
    }

    /**
     * @param {{current_page:number,last_page:number,total:number,from?:number,to?:number}} pagination
     * @param {{links:string|Element, info?:string|Element, label?:string, span?:number,
     *          scrollToTop?:boolean, onNavigate:function(number):void}} options
     */
    function renderAdminPagination(pagination, options) {
        options = options || {};
        var linksEl = el(options.links || '#paginationLinks');
        var infoEl = el(options.info);
        var label = options.label || 'entries';
        var span = typeof options.span === 'number' ? options.span : 2;

        if (!linksEl) return;

        var total = pagination ? Number(pagination.total || 0) : 0;
        if (!pagination || total === 0) {
            if (infoEl) infoEl.innerHTML = 'Showing 0 ' + label;
            linksEl.innerHTML = '';
            return;
        }

        var current = Number(pagination.current_page || 1);
        var last = Number(pagination.last_page || 1);

        if (infoEl) {
            /* Not every endpoint sends from/to, so derive them from per_page. */
            var perPage = Number(pagination.per_page || 0) || 20;
            var from = pagination.from != null ? pagination.from : ((current - 1) * perPage) + 1;
            var to = pagination.to != null ? pagination.to : Math.min(total, current * perPage);
            infoEl.innerHTML = 'Showing <strong>' + from + '</strong> to <strong>' + to
                + '</strong> of <strong>' + total + '</strong> ' + label;
        }

        if (last <= 1) {
            linksEl.innerHTML = '';
            bind(linksEl, options);
            return;
        }

        var html = '<nav aria-label="' + label + ' pages"><ul class="pagination pagination-sm justify-content-center mb-0">';

        html += current > 1
            ? item(button(current - 1, 'Previous', 'Previous page'))
            : item(disabled('Previous'), 'disabled');

        pageWindow(current, last, span).forEach(function (page) {
            if (page === current) {
                html += item('<span class="page-link" aria-current="page">' + page + '</span>', 'active');
            } else {
                html += item(button(page, page, 'Page ' + page));
            }
        });

        html += current < last
            ? item(button(current + 1, 'Next', 'Next page'))
            : item(disabled('Next'), 'disabled');

        html += '</ul></nav>';
        linksEl.innerHTML = html;

        bind(linksEl, options);
    }

    /* One delegated listener per container, however many times we re-render. */
    function bind(linksEl, options) {
        if (linksEl[BOUND]) return;
        linksEl[BOUND] = true;

        linksEl.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-page]');
            if (!btn) return;

            e.preventDefault();
            var page = parseInt(btn.dataset.page, 10);
            if (!page || page < 1) return;

            if (typeof options.onNavigate === 'function') {
                options.onNavigate(page);
            }
            if (options.scrollToTop !== false) {
                global.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    }

    global.renderAdminPagination = renderAdminPagination;
})(typeof window !== 'undefined' ? window : this);
