/**
 * Listing brief editor — same Quill Snow toolbar as publisher My Sites.
 * The textarea is the submitted field. Quill is an upgrade; without it
 * staff can still type in the textarea.
 */
(function (global) {
    'use strict';

    function plainText(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html || '';
        return String(tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
    }

    function wordCount(text) {
        if (!text) return 0;
        return text.split(/\s+/).filter(Boolean).length;
    }

    function updateCounter(root, html) {
        var el = root.querySelector('[data-site-desc-counter]');
        if (!el) return;
        var min = parseInt(root.getAttribute('data-min-chars') || '50', 10);
        var maxChars = parseInt(root.getAttribute('data-max-chars') || '5000', 10);
        var maxWords = parseInt(root.getAttribute('data-max-words') || '500', 10);
        var text = plainText(html);
        var words = wordCount(text);
        var chars = text.length;
        el.textContent = chars + ' characters · ' + words + ' words';
        el.classList.toggle('is-invalid', chars > 0 && (chars < min || chars > maxChars || words > maxWords));
        el.classList.toggle('is-ok', chars >= min && chars <= maxChars && words <= maxWords);
    }

    function toolbar() {
        return [
            [{ header: [1, 2, 3, false] }],
            ['bold', 'italic', 'underline'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['link']
        ];
    }

    function initOne(root) {
        if (!root || root.dataset.editorReady === '1') return;
        var surface = root.querySelector('[data-site-desc-surface]');
        var input = root.querySelector('.site-description-editor__input');
        if (!input) return;

        var placeholder = root.getAttribute('data-placeholder') || 'Enter site description...';

        function syncFrom(html) {
            input.value = html;
            updateCounter(root, html);
        }

        if (typeof global.Quill === 'undefined' || !surface) {
            input.removeAttribute('hidden');
            input.addEventListener('input', function () {
                updateCounter(root, input.value);
            });
            updateCounter(root, input.value);
            root.dataset.editorReady = '1';
            return;
        }

        var initial = input.value || surface.innerHTML;
        var quill = new global.Quill(surface, {
            theme: 'snow',
            placeholder: placeholder,
            modules: { toolbar: toolbar() }
        });
        if (initial && initial.trim() !== '') {
            quill.root.innerHTML = initial;
        }
        quill.on('text-change', function () {
            syncFrom(quill.root.innerHTML);
        });
        var form = root.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                syncFrom(quill.root.innerHTML);
            });
        }
        syncFrom(quill.root.innerHTML);
        input.setAttribute('hidden', 'hidden');
        root.classList.add('is-ready');
        root.dataset.editorReady = '1';
    }

    function initAll(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;
        root.querySelectorAll('[data-site-description-editor]').forEach(initOne);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAll(document); });
    } else {
        initAll(document);
    }

    global.initSiteDescriptionEditor = initAll;
})(window);
