@extends('admin.layouts.app')

@section('content')
@php
    $selectedCountry = strtolower(trim(scalar_text($selectedCountry ?? '')));
    $missingMarket = (bool) ($missingMarket ?? false);
    $missingMarketCount = (int) ($missingMarketCount ?? 0);
    $healthFilter = \App\Support\CatalogHealthQueue::normalize($healthFilter ?? ($missingMarket ? \App\Support\CatalogHealthQueue::MISSING_MARKET : null));
    $liveFilter = (bool) ($liveFilter ?? false);
    $liveCount = (int) ($liveCount ?? 0);
    $healthCounts = $healthCounts ?? \App\Support\CatalogHealthQueue::emptyCounts();
    $healthLabels = \App\Support\CatalogHealthQueue::LABELS;
    $countries = collect($countries ?? []);
    $totalSites = (int) ($totalSites ?? 0);
    $recordsShowing = is_object($sites ?? null) && method_exists($sites, 'total')
        ? (int) $sites->total()
        : (is_countable($sites ?? null) ? count($sites) : 0);
    $exportUrl = $exportUrl ?? route('admin.sites.records.export');
    $selectedLabel = '';
    if ($selectedCountry !== '') {
        $match = $countries->first(fn ($c) => strtolower(trim(scalar_text(data_get($c, 'code')))) === $selectedCountry);
        $selectedLabel = $match
            ? ((scalar_text(data_get($match, 'name')) ?: strtoupper($selectedCountry)).' ('.strtoupper($selectedCountry).')')
            : strtoupper($selectedCountry);
    }
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Websites records sheet</h4>
            <p class="text-muted mb-0 small">
                Live from database — refreshes on every load. Columns: URL, active, countries, categories, catalog health.
            </p>
            @if($missingMarketCount > 0)
                <p class="mb-0 mt-1 small" id="recordsMissingMarketNote">
                    <span class="badge text-bg-danger" id="recordsMissingMarketBadge">{{ $missingMarketCount }}</span>
                    active site{{ $missingMarketCount === 1 ? '' : 's' }} missing a marketplace country
                </p>
            @endif
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
                            @else
                                @if($liveFilter)
                                    <span class="badge text-bg-success records-country-chip me-1">Live on portal</span>
                                @endif
                                @if($selectedCountry !== '')
                                    <span class="badge text-bg-dark records-country-chip">
                                        {{ $selectedLabel }}
                                        <button type="button" class="btn-close btn-close-white ms-1" style="font-size:0.55rem;" id="recordsChipClear" aria-label="Clear country filter"></button>
                                    </span>
                                @else
                                    <span class="text-muted">All countries</span>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-2 mb-2" data-records-listing-filters>
                        @php
                            $allRecordsUrl = route('admin.sites.records', array_filter([
                                'country' => $selectedCountry !== '' ? $selectedCountry : null,
                            ]));
                            $liveRecordsUrl = route('admin.sites.records', array_filter([
                                'live' => 1,
                                'country' => $selectedCountry !== '' ? $selectedCountry : null,
                            ]));
                        @endphp
                        <a href="{{ $allRecordsUrl }}"
                           class="btn btn-sm {{ ! $liveFilter && ! $healthFilter ? 'btn-dark' : 'btn-outline-secondary' }}"
                           data-listing="all">
                            All records
                        </a>
                        <a href="{{ $liveRecordsUrl }}"
                           class="btn btn-sm {{ $liveFilter ? 'btn-success' : 'btn-outline-success' }}"
                           data-listing="live"
                           id="recordsLiveBtn">
                            Live on portal
                            <span class="badge text-bg-light text-success ms-1 {{ $liveCount < 1 ? 'd-none' : '' }}"
                                  id="recordsLiveBtnCount">{{ $liveCount }}</span>
                        </a>
                    </div>
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
                        Showing {{ $recordsShowing }} site{{ $recordsShowing === 1 ? '' : 's' }}
                        @if($healthFilter)
                            in <strong>{{ $healthLabels[$healthFilter] ?? $healthFilter }}</strong>
                        @elseif($liveFilter && $selectedCountry !== '')
                            in <strong>Live on portal</strong> · <strong class="text-uppercase">{{ $selectedCountry }}</strong>
                        @elseif($liveFilter)
                            in <strong>Live on portal</strong>
                        @elseif($selectedCountry !== '')
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
            'missingMarket' => $missingMarket,
            'healthFilter' => $healthFilter,
            'liveFilter' => $liveFilter,
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
    let liveFilter = @json((bool) $liveFilter);
    let liveCount = @json((int) $liveCount);
    const HEALTH_LABELS = @json($healthLabels);

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
        liveFilter = !!meta.live;
        if (typeof meta.live_count === 'number') {
            liveCount = meta.live_count;
        }
        if (meta.health_counts && typeof meta.health_counts === 'object') {
            healthCounts = meta.health_counts;
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
            } else if (liveFilter && selectedCountry) {
                showingLabel.innerHTML = `Showing ${total} ${plural} in <strong>Live on portal</strong> · <strong class="text-uppercase">${escapeHtml(selectedCountry)}</strong>`;
            } else if (liveFilter) {
                showingLabel.innerHTML = `Showing ${total} ${plural} in <strong>Live on portal</strong>`;
            } else if (selectedCountry) {
                showingLabel.innerHTML = `Showing ${total} ${plural} in <strong class="text-uppercase">${escapeHtml(selectedCountry)}</strong>`;
            } else {
                showingLabel.innerHTML = `Showing ${total} ${plural}`;
            }
        }

        document.querySelectorAll('[data-records-listing-filters] [data-listing]').forEach((btn) => {
            const key = btn.getAttribute('data-listing');
            const isLive = key === 'live';
            const isAll = key === 'all';
            btn.classList.toggle('btn-success', isLive && liveFilter);
            btn.classList.toggle('btn-outline-success', isLive && !liveFilter);
            btn.classList.toggle('btn-dark', isAll && !liveFilter && !healthOn);
            btn.classList.toggle('btn-outline-secondary', isAll && (liveFilter || healthOn));
        });
        const liveCountEl = document.getElementById('recordsLiveBtnCount');
        if (liveCountEl) {
            liveCountEl.textContent = String(liveCount);
            liveCountEl.classList.toggle('d-none', liveCount < 1);
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
            } else {
                let html = '';
                if (liveFilter) {
                    html += '<span class="badge text-bg-success records-country-chip me-1">Live on portal</span>';
                }
                if (selectedCountry) {
                    const match = COUNTRIES.find((c) => c.code === selectedCountry);
                    const label = match ? countryLabel(match) : selectedCountry.toUpperCase();
                    html += `
                    <span class="badge text-bg-dark records-country-chip">
                        ${escapeHtml(label)}
                        <button type="button" class="btn-close btn-close-white ms-1" style="font-size:0.55rem;" data-chip-clear aria-label="Clear country filter"></button>
                    </span>
                    `;
                } else {
                    html += '<span class="text-muted">All countries</span>';
                }
                chipWrap.innerHTML = html;
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
        if (options.health === 'missing_market' || options.missingMarket) {
            params.set('missing_market', '1');
        } else if (options.health) {
            params.set('health', options.health);
        } else {
            const nextLive = Object.prototype.hasOwnProperty.call(options, 'live')
                ? !!options.live
                : liveFilter;
            const nextCountry = Object.prototype.hasOwnProperty.call(options, 'country')
                ? String(options.country || '')
                : selectedCountry;
            if (nextLive) params.set('live', '1');
            if (nextCountry) params.set('country', nextCountry);
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

            const nextParams = new URLSearchParams();
            if (data.health === 'missing_market' || data.missing_market) nextParams.set('missing_market', '1');
            else if (data.health) nextParams.set('health', data.health);
            else {
                if (data.live) nextParams.set('live', '1');
                if (data.selected_country) nextParams.set('country', data.selected_country);
            }
            const nextUrl = nextParams.toString() ? `${RECORDS_URL}?${nextParams}` : RECORDS_URL;
            window.history.replaceState({}, '', nextUrl);
        } catch (err) {
            console.error(err);
            if (typeof Swal !== 'undefined') {
                showAppToast('Could not filter records', 'error');
            }
        } finally {
            if (token !== fetchToken) {
                return;
            }
            tableWrap.dataset.loading = '0';
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
        loadRecords({ country: '' });
        setOpen(false);
    });

    chipWrap?.addEventListener('click', (e) => {
        if (e.target.closest('[data-chip-clear], #recordsChipClear, .btn-close')) {
            if (healthFilter) {
                loadRecords({ live: false, country: '' });
            } else {
                loadRecords({ country: '' });
            }
        }
    });

    document.querySelector('[data-records-listing-filters]')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-listing]');
        if (!btn) return;
        e.preventDefault();
        const key = btn.getAttribute('data-listing');
        if (key === 'live') {
            loadRecords({ live: true });
        } else {
            loadRecords({ live: false });
        }
        setOpen(false);
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
