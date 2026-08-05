@extends('admin.layouts.app')

@section('content')
@php
    $selectedCountry = $selectedCountry ?? '';
    $countries = collect($countries ?? []);
    $totalSites = (int) ($totalSites ?? 0);
    $exportUrl = $exportUrl ?? route('admin.sites.records.export');
    $selectedLabel = '';
    if ($selectedCountry !== '') {
        $match = $countries->first(fn ($c) => strtolower((string) ($c['code'] ?? '')) === $selectedCountry);
        $selectedLabel = $match
            ? (($match['name'] ?? strtoupper($selectedCountry)).' ('.strtoupper($selectedCountry).')')
            : strtoupper($selectedCountry);
    }
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Websites records sheet</h4>
            <p class="text-muted mb-0 small">
                Live from database — refreshes on every load. Columns: URL, countries, categories only.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ $exportUrl }}" id="recordsExportBtn" class="btn btn-sm btn-primary">
                <i class="fa fa-download me-1"></i> Download CSV
            </a>
            <a href="{{ route('admin.sites.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-arrow-left me-1"></i> Back to Sites
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-8 col-md-6 col-lg-4">
                    <label for="recordsCountrySearch" class="form-label small fw-semibold mb-1">Filter by country</label>
                    <div data-records-country-filter>
                        <div class="records-country-combo">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white"><i class="fa fa-search text-muted"></i></span>
                                <input type="search"
                                       id="recordsCountrySearch"
                                       class="form-control"
                                       placeholder="Search countries…"
                                       autocomplete="off"
                                       aria-autocomplete="list"
                                       aria-controls="recordsCountryList"
                                       aria-expanded="false">
                                <button type="button"
                                        class="btn btn-outline-secondary {{ $selectedCountry === '' ? 'd-none' : '' }}"
                                        id="recordsCountryClear"
                                        title="Show all countries">
                                    Clear
                                </button>
                            </div>
                            <div id="recordsCountryList"
                                 class="records-country-list list-group shadow-sm d-none"
                                 role="listbox"
                                 hidden></div>
                        </div>
                        <div class="mt-2 small" id="recordsSelectedChipWrap">
                            @if($selectedCountry !== '')
                                <span class="badge text-bg-dark records-country-chip">
                                    {{ $selectedLabel }}
                                    <button type="button" class="btn-close btn-close-white ms-1" style="font-size:0.55rem;" id="recordsChipClear" aria-label="Clear country filter"></button>
                                </span>
                            @else
                                <span class="text-muted">All countries</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md text-md-end">
                    <span class="small text-muted" id="recordsShowingLabel">
                        Showing {{ $sites->total() }} site{{ $sites->total() === 1 ? '' : 's' }}
                        @if($selectedCountry !== '')
                            in <strong class="text-uppercase">{{ $selectedCountry }}</strong>
                        @endif
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div id="recordsTableWrap" data-loading="0">
        @include('admin.sites.partials.records-table', [
            'sites' => $sites,
            'selectedCountry' => $selectedCountry,
        ])
    </div>
</div>


<script>
(function () {
    const RECORDS_URL = @json(route('admin.sites.records'));
    const EXPORT_BASE = @json(route('admin.sites.records.export'));
    const TOTAL_SITES = @json($totalSites);
    const COUNTRIES = @json($countries->values());
    let selectedCountry = @json($selectedCountry);

    const searchInput = document.getElementById('recordsCountrySearch');
    const listEl = document.getElementById('recordsCountryList');
    const clearBtn = document.getElementById('recordsCountryClear');
    const chipWrap = document.getElementById('recordsSelectedChipWrap');
    const showingLabel = document.getElementById('recordsShowingLabel');
    const exportBtn = document.getElementById('recordsExportBtn');
    const tableWrap = document.getElementById('recordsTableWrap');
    if (!searchInput || !listEl || !tableWrap) return;

    let open = false;
    let activeIndex = -1;
    let visibleItems = [];
    let fetchToken = 0;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function countryLabel(item) {
        return `${item.name} (${String(item.code || '').toUpperCase()})`;
    }

    function filteredCountries(query) {
        const q = String(query || '').trim().toLowerCase();
        const rows = COUNTRIES.slice();
        if (!q) return rows;
        return rows.filter((c) => {
            const name = String(c.name || '').toLowerCase();
            const code = String(c.code || '').toLowerCase();
            return name.includes(q) || code.includes(q);
        });
    }

    function renderList(query) {
        const rows = filteredCountries(query);
        visibleItems = [{ code: '', name: 'All countries', count: TOTAL_SITES }, ...rows];
        activeIndex = -1;

        listEl.innerHTML = visibleItems.map((item, index) => {
            const isAll = item.code === '';
            const isSelected = (selectedCountry || '') === (item.code || '');
            const zero = !isAll && Number(item.count || 0) === 0;
            const label = isAll ? 'All countries' : countryLabel(item);
            return `
                <button type="button"
                        class="list-group-item list-group-item-action ${isSelected ? 'active' : ''} ${zero ? 'is-zero' : ''}"
                        role="option"
                        data-index="${index}"
                        data-code="${escapeHtml(item.code || '')}"
                        aria-selected="${isSelected ? 'true' : 'false'}">
                    <span>${escapeHtml(label)}</span>
                    <span class="badge rounded-pill ${isSelected ? 'text-bg-light' : 'text-bg-secondary'} count-badge">${Number(item.count || 0)}</span>
                </button>
            `;
        }).join('');
    }

    function setOpen(next) {
        open = !!next;
        listEl.classList.toggle('d-none', !open);
        listEl.hidden = !open;
        searchInput.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            renderList(searchInput.value);
        }
    }

    function updateChrome(meta) {
        selectedCountry = meta.selected_country || '';
        const total = Number(meta.total || 0);
        const exportUrl = meta.export_url || EXPORT_BASE;

        if (exportBtn) exportBtn.href = exportUrl;

        if (clearBtn) {
            clearBtn.classList.toggle('d-none', selectedCountry === '');
        }

        if (showingLabel) {
            const plural = total === 1 ? 'site' : 'sites';
            showingLabel.innerHTML = selectedCountry
                ? `Showing ${total} ${plural} in <strong class="text-uppercase">${escapeHtml(selectedCountry)}</strong>`
                : `Showing ${total} ${plural}`;
        }

        if (chipWrap) {
            if (selectedCountry) {
                const match = COUNTRIES.find((c) => c.code === selectedCountry);
                const label = match ? countryLabel(match) : selectedCountry.toUpperCase();
                chipWrap.innerHTML = `
                    <span class="badge text-bg-dark records-country-chip">
                        ${escapeHtml(label)}
                        <button type="button" class="btn-close btn-close-white ms-1" style="font-size:0.55rem;" data-chip-clear aria-label="Clear country filter"></button>
                    </span>
                `;
            } else {
                chipWrap.innerHTML = '<span class="text-muted">All countries</span>';
            }
        }

        searchInput.value = '';
        if (open) renderList('');
    }

    async function loadCountry(code) {
        const token = ++fetchToken;
        const params = new URLSearchParams();
        params.set('partial', '1');
        if (code) params.set('country', code);

        const url = `${RECORDS_URL}?${params.toString()}`;
        tableWrap.dataset.loading = '1';

        try {
            const res = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (token !== fetchToken) return;
            if (!res.ok || !data.success) {
                throw new Error(data.message || 'Failed to filter records');
            }

            tableWrap.innerHTML = data.table_html || '';
            updateChrome(data);

            const nextParams = new URLSearchParams();
            if (data.selected_country) nextParams.set('country', data.selected_country);
            const nextUrl = nextParams.toString() ? `${RECORDS_URL}?${nextParams}` : RECORDS_URL;
            window.history.replaceState({}, '', nextUrl);
        } catch (err) {
            console.error(err);
            if (typeof Swal !== 'undefined') {
                Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'Could not filter by country', showConfirmButton: false, timer: 2200 });
            }
        } finally {
            if (token === fetchToken) {
                tableWrap.dataset.loading = '0';
            }
        }
    }

    searchInput.addEventListener('focus', () => setOpen(true));
    searchInput.addEventListener('input', () => {
        setOpen(true);
        renderList(searchInput.value);
    });
    searchInput.addEventListener('keydown', (e) => {
        if (!open && (e.key === 'ArrowDown' || e.key === 'Enter')) {
            setOpen(true);
            e.preventDefault();
            return;
        }
        if (!open) return;

        const buttons = [...listEl.querySelectorAll('[data-code]')];
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(buttons.length - 1, activeIndex + 1);
            buttons.forEach((btn, i) => btn.classList.toggle('active', i === activeIndex));
            buttons[activeIndex]?.scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(0, activeIndex - 1);
            buttons.forEach((btn, i) => btn.classList.toggle('active', i === activeIndex));
            buttons[activeIndex]?.scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const btn = buttons[activeIndex] || buttons[0];
            if (btn) {
                loadCountry(btn.dataset.code || '');
                setOpen(false);
            }
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    });

    listEl.addEventListener('mousedown', (e) => {
        const btn = e.target.closest('[data-code]');
        if (!btn) return;
        e.preventDefault();
        loadCountry(btn.dataset.code || '');
        setOpen(false);
    });

    clearBtn?.addEventListener('click', () => {
        loadCountry('');
        setOpen(false);
    });

    chipWrap?.addEventListener('click', (e) => {
        if (e.target.closest('[data-chip-clear], #recordsChipClear, .btn-close')) {
            loadCountry('');
        }
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('[data-records-country-filter]')) {
            setOpen(false);
        }
    });

    tableWrap.addEventListener('click', (e) => {
        const link = e.target.closest('[data-records-pagination] a');
        if (!link) return;
        e.preventDefault();
        const href = link.getAttribute('href');
        if (!href) return;

        const token = ++fetchToken;
        tableWrap.dataset.loading = '1';
        const url = new URL(href, window.location.origin);
        url.searchParams.set('partial', '1');

        fetch(url.toString(), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => {
                if (token !== fetchToken) return;
                if (!data.success) throw new Error('Pagination failed');
                tableWrap.innerHTML = data.table_html || '';
                updateChrome(data);
                const next = new URL(href, window.location.origin);
                next.searchParams.delete('partial');
                window.history.replaceState({}, '', next.pathname + next.search);
            })
            .catch((err) => console.error(err))
            .finally(() => {
                if (token === fetchToken) tableWrap.dataset.loading = '0';
            });
    });
})();
</script>
@endsection
