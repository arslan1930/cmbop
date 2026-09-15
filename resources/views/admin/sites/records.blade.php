@extends('admin.layouts.app')

@section('content')
@php
    $selectedCountry = $selectedCountry ?? '';
    $missingMarket = (bool) ($missingMarket ?? false);
    $missingMarketCount = (int) ($missingMarketCount ?? 0);
    $healthFilter = $healthFilter ?? ($missingMarket ? \App\Support\CatalogHealthQueue::MISSING_MARKET : null);
    $healthCounts = $healthCounts ?? \App\Support\CatalogHealthQueue::emptyCounts();
    $healthLabels = \App\Support\CatalogHealthQueue::LABELS;
    $countries = collect($countries ?? []);
    $totalSites = (int) ($totalSites ?? 0);
    $exportUrl = $exportUrl ?? route('admin.sites.records.export');
    $searchQ = $searchQ ?? '';
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
                Live from database — refreshes on every load. Search, verify, or activate from this sheet.
            </p>
            @if($missingMarketCount > 0)
                <p class="mb-0 mt-1 small" id="recordsMissingMarketNote">
                    <span class="badge text-bg-danger" id="recordsMissingMarketBadge">{{ $missingMarketCount }}</span>
                    active site{{ $missingMarketCount === 1 ? '' : 's' }} missing a marketplace country
                </p>
            @endif
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.sites.duplicates') }}" class="btn btn-sm btn-outline-warning">
                <i class="fa fa-clone me-1"></i> Duplicate domains
            </a>
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
                    <label for="recordsSiteSearch" class="form-label small fw-semibold mb-1">Search sites</label>
                    <input type="search"
                           id="recordsSiteSearch"
                           class="form-control form-control-sm"
                           placeholder="URL, domain, or name…"
                           value="{{ $searchQ }}"
                           autocomplete="off">
                </div>
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
                                       aria-expanded="false"
                                       @disabled($healthFilter)>
                                <button type="button"
                                        class="btn btn-outline-secondary {{ $selectedCountry === '' && ! $healthFilter ? 'd-none' : '' }}"
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
                            @if($healthFilter)
                                <span class="badge {{ $healthFilter === 'missing_market' ? 'text-bg-danger' : 'text-bg-warning' }} records-country-chip">
                                    {{ $healthLabels[$healthFilter] ?? $healthFilter }}
                                    <button type="button" class="btn-close btn-close-white ms-1" style="font-size:0.55rem;" id="recordsChipClear" aria-label="Clear health filter"></button>
                                </span>
                            @elseif($selectedCountry !== '')
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
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-2" data-records-health-filters>
                        @foreach($healthLabels as $healthKey => $healthLabel)
                            @php
                                $healthCount = (int) ($healthCounts[$healthKey] ?? 0);
                                $healthActive = $healthFilter === $healthKey;
                                $healthHref = $healthKey === \App\Support\CatalogHealthQueue::MISSING_MARKET
                                    ? route('admin.sites.records', ['missing_market' => 1])
                                    : route('admin.sites.records', ['health' => $healthKey]);
                                $healthBtnClass = $healthKey === \App\Support\CatalogHealthQueue::MISSING_MARKET
                                    ? ($healthActive ? 'btn-danger' : 'btn-outline-danger')
                                    : ($healthActive ? 'btn-warning' : 'btn-outline-warning');
                            @endphp
                            <a href="{{ $healthHref }}"
                               class="btn btn-sm {{ $healthBtnClass }}"
                               data-health="{{ $healthKey }}"
                               @if($healthKey === \App\Support\CatalogHealthQueue::MISSING_MARKET) id="recordsMissingMarketBtn" @endif>
                                {{ $healthLabel }}
                                <span class="badge text-bg-light {{ $healthKey === \App\Support\CatalogHealthQueue::MISSING_MARKET ? 'text-danger' : 'text-warning' }} ms-1 {{ $healthCount < 1 ? 'd-none' : '' }}"
                                      data-health-count="{{ $healthKey }}"
                                      @if($healthKey === \App\Support\CatalogHealthQueue::MISSING_MARKET) id="recordsMissingMarketBtnCount" @endif>{{ $healthCount }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
                <div class="col-12 text-md-end">
                    <span class="small text-muted" id="recordsShowingLabel">
                        Showing {{ $sites->total() }} site{{ $sites->total() === 1 ? '' : 's' }}
                        @if($healthFilter)
                            in <strong>{{ $healthLabels[$healthFilter] ?? $healthFilter }}</strong>
                        @elseif($selectedCountry !== '')
                            in <strong class="text-uppercase">{{ $selectedCountry }}</strong>
                        @endif
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3" id="recordsBulkBar">
        <span class="small text-muted" id="recordsSelectedCount">0 selected</span>
        <button type="button" class="btn btn-sm btn-success" id="recordsBulkVerify" disabled>
            Verify selected
        </button>
        <button type="button" class="btn btn-sm btn-primary" id="recordsBulkActivate" disabled>
            Activate selected
        </button>
        <span class="small text-muted">Confirm before it runs. Max 50 per batch.</span>
    </div>

    <div id="recordsTableWrap" data-loading="0">
        @include('admin.sites.partials.records-table', [
            'sites' => $sites,
            'selectedCountry' => $selectedCountry,
            'missingMarket' => $missingMarket,
            'healthFilter' => $healthFilter,
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
    let missingMarket = @json((bool) $missingMarket);
    let missingMarketCount = @json((int) $missingMarketCount);
    let healthFilter = @json($healthFilter);
    let healthCounts = @json($healthCounts);
    const HEALTH_LABELS = @json($healthLabels);

    const siteSearch = document.getElementById('recordsSiteSearch');
    let siteQuery = @json($searchQ);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const BULK_VERIFY_URL = @json(route('admin.sites.records.bulk-verify'));
    const BULK_ACTIVATE_URL = @json(route('admin.sites.records.bulk-activate'));
    const searchInput = document.getElementById('recordsCountrySearch');
    const listEl = document.getElementById('recordsCountryList');
    const clearBtn = document.getElementById('recordsCountryClear');
    const chipWrap = document.getElementById('recordsSelectedChipWrap');
    const showingLabel = document.getElementById('recordsShowingLabel');
    const exportBtn = document.getElementById('recordsExportBtn');
    const tableWrap = document.getElementById('recordsTableWrap');
    const missingBtn = document.getElementById('recordsMissingMarketBtn');
    const missingBadge = document.getElementById('recordsMissingMarketBadge');
    const missingBtnCount = document.getElementById('recordsMissingMarketBtnCount');
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
        missingMarket = !!meta.missing_market;
        healthFilter = meta.health || (missingMarket ? 'missing_market' : null);
        if (meta.health_counts && typeof meta.health_counts === 'object') {
            healthCounts = meta.health_counts;
        }
        if (typeof meta.q === 'string') {
            siteQuery = meta.q;
            if (siteSearch && document.activeElement !== siteSearch) {
                siteSearch.value = siteQuery;
            }
        }
        if (typeof meta.missing_market_count === 'number') {
            missingMarketCount = meta.missing_market_count;
            healthCounts.missing_market = missingMarketCount;
        }
        const total = Number(meta.total || 0);
        const exportUrl = meta.export_url || EXPORT_BASE;
        const healthOn = !!healthFilter;

        if (exportBtn) exportBtn.href = exportUrl;
        searchInput.disabled = healthOn;

        if (clearBtn) {
            clearBtn.classList.toggle('d-none', selectedCountry === '' && !healthOn);
        }

        if (showingLabel) {
            const plural = total === 1 ? 'site' : 'sites';
            if (healthOn) {
                showingLabel.innerHTML = `Showing ${total} ${plural} in <strong>${escapeHtml(HEALTH_LABELS[healthFilter] || healthFilter)}</strong>`;
            } else if (selectedCountry) {
                showingLabel.innerHTML = `Showing ${total} ${plural} in <strong class="text-uppercase">${escapeHtml(selectedCountry)}</strong>`;
            } else {
                showingLabel.innerHTML = `Showing ${total} ${plural}`;
            }
            if (siteQuery) {
                showingLabel.innerHTML += ` matching <strong>${escapeHtml(siteQuery)}</strong>`;
            }
        }

        if (chipWrap) {
            if (healthOn) {
                const chipClass = healthFilter === 'missing_market' ? 'text-bg-danger' : 'text-bg-warning';
                chipWrap.innerHTML = `
                    <span class="badge ${chipClass} records-country-chip">
                        ${escapeHtml(HEALTH_LABELS[healthFilter] || healthFilter)}
                        <button type="button" class="btn-close btn-close-white ms-1" style="font-size:0.55rem;" data-chip-clear aria-label="Clear health filter"></button>
                    </span>
                `;
            } else if (selectedCountry) {
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

        document.querySelectorAll('[data-records-health-filters] [data-health]').forEach((btn) => {
            const key = btn.getAttribute('data-health');
            const active = healthFilter === key;
            const isMarket = key === 'missing_market';
            btn.classList.toggle(isMarket ? 'btn-danger' : 'btn-warning', active);
            btn.classList.toggle(isMarket ? 'btn-outline-danger' : 'btn-outline-warning', !active);
            const countEl = btn.querySelector('[data-health-count]');
            const count = Number((healthCounts && healthCounts[key]) || 0);
            if (countEl) {
                countEl.textContent = String(count);
                countEl.classList.toggle('d-none', count < 1);
            }
        });
        if (missingBadge) {
            missingBadge.textContent = String(missingMarketCount);
            missingBadge.closest('p')?.classList.toggle('d-none', missingMarketCount < 1);
        }

        searchInput.value = '';
        if (open) renderList('');
    }

    async function loadRecords(options) {
        options = options || {};
        const token = ++fetchToken;
        const params = new URLSearchParams();
        params.set('partial', '1');
        if (options.q !== undefined) {
            siteQuery = String(options.q || '').trim();
        } else if (siteSearch) {
            siteQuery = String(siteSearch.value || '').trim();
        }
        if (siteQuery) params.set('q', siteQuery);
        if (options.health === 'missing_market' || options.missingMarket) {
            params.set('missing_market', '1');
        } else if (options.health) {
            params.set('health', options.health);
        } else if (options.country) {
            params.set('country', options.country);
        }

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
            syncRecordsSelection();

            const nextParams = new URLSearchParams();
            if (data.health === 'missing_market' || data.missing_market) nextParams.set('missing_market', '1');
            else if (data.health) nextParams.set('health', data.health);
            else if (data.selected_country) nextParams.set('country', data.selected_country);
            if (siteQuery) nextParams.set('q', siteQuery);
            const nextUrl = nextParams.toString() ? `${RECORDS_URL}?${nextParams}` : RECORDS_URL;
            window.history.replaceState({}, '', nextUrl);
        } catch (err) {
            console.error(err);
            if (typeof Swal !== 'undefined') {
                showAppToast('Could not filter records', 'error');
            }
        } finally {
            if (token === fetchToken) {
                tableWrap.dataset.loading = '0';
            }
        }
    }

    async function loadCountry(code) {
        return loadRecords({ country: code || '' });
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
        loadRecords({});
        setOpen(false);
    });

    chipWrap?.addEventListener('click', (e) => {
        if (e.target.closest('[data-chip-clear], #recordsChipClear, .btn-close')) {
            loadRecords({});
        }
    });

    document.querySelector('[data-records-health-filters]')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-health]');
        if (!btn) return;
        e.preventDefault();
        const key = btn.getAttribute('data-health');
        if (healthFilter === key) {
            loadRecords({});
        } else {
            loadRecords({ health: key });
        }
        setOpen(false);
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
                syncRecordsSelection();
                const next = new URL(href, window.location.origin);
                next.searchParams.delete('partial');
                window.history.replaceState({}, '', next.pathname + next.search);
            })
            .catch((err) => console.error(err))
            .finally(() => {
                if (token === fetchToken) tableWrap.dataset.loading = '0';
            });
    });

    let searchTimer = 0;
    siteSearch?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            loadRecords({
                q: siteSearch.value,
                health: healthFilter || undefined,
                country: selectedCountry || undefined,
                missingMarket: healthFilter === 'missing_market',
            });
        }, 250);
    });

    function selectedRecordIds() {
        return [...tableWrap.querySelectorAll('.js-records-row:checked')].map((el) => Number(el.value));
    }

    function syncRecordsSelection() {
        const boxes = [...tableWrap.querySelectorAll('.js-records-row')];
        const selected = boxes.filter((el) => el.checked);
        const countEl = document.getElementById('recordsSelectedCount');
        const verifyBtn = document.getElementById('recordsBulkVerify');
        const activateBtn = document.getElementById('recordsBulkActivate');
        const all = document.getElementById('recordsSelectAll');
        if (countEl) countEl.textContent = selected.length + ' selected';
        if (verifyBtn) verifyBtn.disabled = selected.length < 1;
        if (activateBtn) activateBtn.disabled = selected.length < 1;
        if (all) {
            all.checked = boxes.length > 0 && selected.length === boxes.length;
            all.indeterminate = selected.length > 0 && selected.length < boxes.length;
        }
    }

    tableWrap.addEventListener('change', (e) => {
        if (e.target.id === 'recordsSelectAll') {
            tableWrap.querySelectorAll('.js-records-row').forEach((el) => {
                el.checked = e.target.checked;
            });
        }
        if (e.target.id === 'recordsSelectAll' || e.target.classList.contains('js-records-row')) {
            syncRecordsSelection();
        }
    });

    async function runRecordsBulk(url, title, text, confirmText) {
        const ids = selectedRecordIds();
        if (!ids.length) return;
        const ok = window.slbConfirm
            ? await window.slbConfirm({
                title,
                text,
                confirmText,
                danger: true,
            })
            : window.confirm(text);
        if (!ok) return;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ids }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                throw new Error(data.message || 'Bulk update failed');
            }
            if (window.showAppToast) {
                window.showAppToast(data.message || 'Updated', 'success');
            }
            await loadRecords({
                health: healthFilter || undefined,
                country: selectedCountry || undefined,
                missingMarket: healthFilter === 'missing_market',
            });
        } catch (err) {
            if (window.showAppToast) {
                window.showAppToast(err.message || 'Bulk update failed', 'error');
            } else if (typeof Swal !== 'undefined') {
                Swal.fire('Error', err.message || 'Bulk update failed', 'error');
            }
        }
    }

    document.getElementById('recordsBulkVerify')?.addEventListener('click', () => {
        const n = selectedRecordIds().length;
        runRecordsBulk(
            BULK_VERIFY_URL,
            'Verify selected sites?',
            'Manually verify ' + n + ' site' + (n === 1 ? '' : 's') + '? This is the same as Verify on each listing.',
            'Verify selected'
        );
    });
    document.getElementById('recordsBulkActivate')?.addEventListener('click', () => {
        const n = selectedRecordIds().length;
        runRecordsBulk(
            BULK_ACTIVATE_URL,
            'Activate selected sites?',
            'Turn ' + n + ' site' + (n === 1 ? '' : 's') + ' live in the catalog? Listings that fail the go-live gates stay off.',
            'Activate selected'
        );
    });

    syncRecordsSelection();
})();
</script>
@endsection
