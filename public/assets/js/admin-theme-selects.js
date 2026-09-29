/**
 * Themed listboxes for admin filter bars that use .admin-deposits-filters.
 * Closed controls and the open menu share the platform brand tokens.
 * A pick reloads a GET filter form. Bars marked data-admin-filter-live="1"
 * dispatch admin-filter-pick instead, so AJAX consoles can reload themselves.
 */
(function () {
    function labelFor(select) {
        if (select.id) {
            const labelled = document.querySelector('label[for="' + select.id + '"]');
            if (labelled) {
                return labelled.textContent.replace(/\s+/g, ' ').trim();
            }
        }
        const parentLabel = select.closest('label');
        if (parentLabel) {
            const clone = parentLabel.cloneNode(true);
            clone.querySelectorAll('select, input, button').forEach(function (el) {
                el.remove();
            });
            const text = clone.textContent.replace(/\s+/g, ' ').trim();
            if (text) return text;
        }
        return select.getAttribute('aria-label') || 'Choose';
    }

    function enhance(select) {
        if (select.closest('.admin-deposits-theme-select, .community-theme-select')) return;
        const parent = select.parentNode;
        if (!parent) return;

        const wrap = document.createElement('div');
        wrap.className = 'single-select-wrapper admin-deposits-theme-select';
        parent.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('visually-hidden');
        select.tabIndex = -1;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'single-select-input';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-label', labelFor(select));

        const valueEl = document.createElement('span');
        valueEl.className = 'single-select-value';
        const arrow = document.createElement('i');
        arrow.className = 'fa fa-chevron-down single-select-arrow';
        arrow.setAttribute('aria-hidden', 'true');
        trigger.append(valueEl, arrow);

        const dropdown = document.createElement('div');
        dropdown.className = 'single-select-dropdown';
        const searchable = select.dataset.adminSelectSearch === '1';
        let searchInput = null;
        if (searchable) {
            const search = document.createElement('div');
            search.className = 'single-select-search';
            searchInput = document.createElement('input');
            searchInput.type = 'search';
            const searchLabel = select.dataset.adminSelectSearchLabel || 'Search';
            searchInput.setAttribute('placeholder', searchLabel);
            searchInput.setAttribute('aria-label', searchLabel);
            searchInput.autocomplete = 'off';
            search.appendChild(searchInput);
            dropdown.appendChild(search);
            search.addEventListener('click', function (event) {
                event.stopPropagation();
            });
            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });
            searchInput.addEventListener('input', function () {
                filterOptions(searchInput.value);
            });
        }
        const options = document.createElement('div');
        options.className = 'single-select-options';
        options.setAttribute('role', 'listbox');
        dropdown.appendChild(options);
        wrap.append(trigger, dropdown);

        function filterOptions(term) {
            const query = String(term || '').trim().toLowerCase();
            let visible = 0;
            options.querySelectorAll('.single-select-option').forEach(function (el) {
                const show = query === '' || el.textContent.toLowerCase().indexOf(query) !== -1;
                el.hidden = !show;
                if (show) visible += 1;
            });
            options.querySelectorAll('.single-select-group').forEach(function (group) {
                let sibling = group.nextElementSibling;
                let any = false;
                while (sibling && !sibling.classList.contains('single-select-group')) {
                    if (sibling.classList.contains('single-select-option') && !sibling.hidden) any = true;
                    sibling = sibling.nextElementSibling;
                }
                group.hidden = !any;
            });
            let empty = options.querySelector('.single-select-empty');
            if (!visible) {
                if (!empty) {
                    empty = document.createElement('div');
                    empty.className = 'single-select-empty';
                    empty.textContent = select.dataset.adminSelectSearchEmpty || 'No matches';
                    options.appendChild(empty);
                }
                empty.hidden = false;
            } else if (empty) {
                empty.hidden = true;
            }
        }

        function appendOption(opt, current) {
            const el = document.createElement('div');
            const on = opt.value === current;
            el.className = 'single-select-option' + (on ? ' selected' : '');
            el.setAttribute('role', 'option');
            el.setAttribute('data-value', opt.value);
            el.setAttribute('aria-selected', on ? 'true' : 'false');
            el.textContent = (opt.textContent || '').trim();
            options.appendChild(el);
        }

        function sync() {
            const current = String(select.value || '');
            options.replaceChildren();
            Array.from(select.children).forEach(function (child) {
                if (child.tagName === 'OPTGROUP') {
                    const grouped = Array.from(child.children).filter(function (opt) {
                        return opt.tagName === 'OPTION';
                    });
                    if (!grouped.length) return;
                    const label = document.createElement('div');
                    label.className = 'single-select-group';
                    label.textContent = child.label || '';
                    options.appendChild(label);
                    grouped.forEach(function (opt) {
                        appendOption(opt, current);
                    });
                    return;
                }
                if (child.tagName === 'OPTION' && !child.disabled) appendOption(child, current);
            });
            const selected = select.options[select.selectedIndex];
            valueEl.textContent = selected ? String(selected.textContent || '').trim() : 'All';
            if (searchInput) filterOptions(searchInput.value);
        }

        select.addEventListener('change', sync);

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const willOpen = !dropdown.classList.contains('show');
            document.querySelectorAll('.admin-deposits-filters .single-select-dropdown.show').forEach(function (dd) {
                if (dd === dropdown) return;
                dd.classList.remove('show');
                const other = dd.parentElement && dd.parentElement.querySelector('.single-select-input');
                if (other) other.setAttribute('aria-expanded', 'false');
            });
            dropdown.classList.toggle('show', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen && searchInput) {
                searchInput.value = '';
                filterOptions('');
                window.setTimeout(function () { searchInput.focus(); }, 0);
            }
        });

        dropdown.addEventListener('click', function (event) {
            const opt = event.target.closest('.single-select-option');
            if (!opt) return;
            event.stopPropagation();
            select.value = opt.getAttribute('data-value') || '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
            dropdown.classList.remove('show');
            trigger.setAttribute('aria-expanded', 'false');

            const root = select.closest('.admin-deposits-filters');
            if (root && root.dataset.adminFilterLive === '1') {
                root.dispatchEvent(new CustomEvent('admin-filter-pick', { bubbles: true }));
                return;
            }
            const form = select.form;
            if (form && form.classList.contains('admin-deposits-filters')) {
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            }
        });

        sync();
    }

    function boot() {
        document.querySelectorAll('.admin-deposits-filters select').forEach(enhance);
    }

    document.addEventListener('click', function () {
        document.querySelectorAll('.admin-deposits-filters .single-select-dropdown.show').forEach(function (dd) {
            dd.classList.remove('show');
            const trigger = dd.parentElement && dd.parentElement.querySelector('.single-select-input');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
