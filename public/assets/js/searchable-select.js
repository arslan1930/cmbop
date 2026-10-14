/* public/assets/js/searchable-select.js — themed searchable <select> */
(function () {
    function textOf(option) {
        return String(option.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function enhance(select) {
        if (!select || select.dataset.slbSearchReady === '1') {
            return;
        }
        select.dataset.slbSearchReady = '1';

        const wrap = document.createElement('div');
        wrap.className = 'slb-search-select';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('slb-search-select__native');

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'slb-search-select__toggle';
        toggle.setAttribute('aria-haspopup', 'listbox');
        toggle.setAttribute('aria-expanded', 'false');
        if (select.id) {
            toggle.setAttribute('aria-controls', select.id + '-listbox');
        }
        if (select.required) {
            toggle.setAttribute('aria-required', 'true');
        }
        wrap.appendChild(toggle);

        const panel = document.createElement('div');
        panel.className = 'slb-search-select__panel';
        panel.hidden = true;

        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'slb-search-select__query';
        search.setAttribute('placeholder', 'Search countries…');
        search.setAttribute('autocomplete', 'off');
        search.setAttribute('aria-label', 'Search countries');
        panel.appendChild(search);

        const list = document.createElement('ul');
        list.className = 'slb-search-select__list';
        list.setAttribute('role', 'listbox');
        if (select.id) {
            list.id = select.id + '-listbox';
        }
        panel.appendChild(list);
        wrap.appendChild(panel);

        let items = [];
        let activeIndex = -1;

        function selectedOption() {
            return select.options[select.selectedIndex] || null;
        }

        function syncToggle() {
            const opt = selectedOption();
            const label = opt && opt.value ? textOf(opt) : 'Select your country';
            toggle.textContent = label;
            toggle.classList.toggle('is-placeholder', !(opt && opt.value));
        }

        function optionItems() {
            return Array.from(select.options).filter(function (opt) {
                return opt.value !== '';
            });
        }

        function renderList(query) {
            const q = String(query || '').trim().toLowerCase();
            list.innerHTML = '';
            items = [];
            optionItems().forEach(function (opt) {
                const label = textOf(opt);
                if (q && label.toLowerCase().indexOf(q) === -1 && String(opt.value).toLowerCase().indexOf(q) === -1) {
                    return;
                }
                const li = document.createElement('li');
                li.className = 'slb-search-select__option';
                li.setAttribute('role', 'option');
                li.setAttribute('data-value', opt.value);
                li.textContent = label;
                if (opt.selected) {
                    li.setAttribute('aria-selected', 'true');
                    li.classList.add('is-selected');
                } else {
                    li.setAttribute('aria-selected', 'false');
                }
                list.appendChild(li);
                items.push(li);
            });
            if (!items.length) {
                const empty = document.createElement('li');
                empty.className = 'slb-search-select__empty';
                empty.textContent = 'No matching countries';
                list.appendChild(empty);
            }
            activeIndex = items.findIndex(function (el) {
                return el.classList.contains('is-selected');
            });
            setActive(activeIndex);
        }

        function setActive(index) {
            items.forEach(function (el) {
                el.classList.remove('is-active');
            });
            if (index < 0 || index >= items.length) {
                activeIndex = -1;
                return;
            }
            activeIndex = index;
            items[activeIndex].classList.add('is-active');
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        function placePanel() {
            const rect = toggle.getBoundingClientRect();
            panel.style.position = 'fixed';
            panel.style.left = Math.max(8, rect.left) + 'px';
            panel.style.width = Math.max(rect.width, 220) + 'px';
            panel.style.zIndex = '1080';
            const spaceBelow = window.innerHeight - rect.bottom - 12;
            const spaceAbove = rect.top - 12;
            if (spaceBelow < 180 && spaceAbove > spaceBelow) {
                panel.style.top = 'auto';
                panel.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
            } else {
                panel.style.bottom = 'auto';
                panel.style.top = (rect.bottom + 4) + 'px';
            }
        }

        function openPanel() {
            if (select.disabled) {
                return;
            }
            renderList('');
            search.value = '';
            panel.hidden = false;
            wrap.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
            placePanel();
            search.focus();
        }

        function closePanel() {
            panel.hidden = true;
            wrap.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function pick(value) {
            if (select.value !== value) {
                select.value = value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                select.dispatchEvent(new Event('input', { bubbles: true }));
            }
            select.classList.remove('is-invalid');
            syncToggle();
            closePanel();
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (panel.hidden) {
                openPanel();
            } else {
                closePanel();
            }
        });

        search.addEventListener('input', function () {
            renderList(search.value);
        });

        search.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(Math.min(items.length - 1, activeIndex + 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(Math.max(0, activeIndex - 1));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (items[activeIndex]) {
                    pick(items[activeIndex].getAttribute('data-value'));
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closePanel();
                toggle.focus();
            }
        });

        list.addEventListener('mousedown', function (e) {
            const li = e.target.closest('.slb-search-select__option');
            if (!li) {
                return;
            }
            e.preventDefault();
            pick(li.getAttribute('data-value'));
        });

        document.addEventListener('mousedown', function (e) {
            if (!wrap.contains(e.target)) {
                closePanel();
            }
        });

        window.addEventListener('resize', function () {
            if (!panel.hidden) {
                placePanel();
            }
        });
        window.addEventListener('scroll', function () {
            if (!panel.hidden) {
                closePanel();
            }
        }, true);

        select.addEventListener('invalid', function () {
            openPanel();
        });

        select.addEventListener('change', syncToggle);

        syncToggle();
    }

    function init(root) {
        (root || document).querySelectorAll('select.js-slb-search-select').forEach(enhance);
    }

    window.SlbSearchSelect = { init: init, enhance: enhance };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init();
        });
    } else {
        init();
    }
})();
