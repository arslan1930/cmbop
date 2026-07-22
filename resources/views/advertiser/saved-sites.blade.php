@extends('advertiser.layouts.app')

@section('content')
@php
    $tab = $tab ?? 'favorites';
    $favorites = $favorites ?? collect();
    $blacklist = $blacklist ?? collect();
    $favoritesCount = $favoritesCount ?? $favorites->count();
    $blacklistCount = $blacklistCount ?? $blacklist->count();

    $maskHost = function (?string $url): array {
        $rawHost = (string) \Illuminate\Support\Str::of((string) $url)
            ->replaceMatches('/^(https?:\/\/)?(www\.)?/', '')
            ->before('/');
        $hostParts = explode('.', $rawHost);
        if (count($hostParts) >= 2) {
            $tld = array_pop($hostParts);
            $namePart = implode('.', $hostParts);
            $visibleLen = min(4, max(2, strlen($namePart)));
            $maskedHost = substr($namePart, 0, $visibleLen).'***.'.$tld;
        } else {
            $maskedHost = substr($rawHost, 0, 3).'******';
        }

        return [$maskedHost, $rawHost];
    };
@endphp

<style>
.saved-kpi {
    display: flex; align-items: center; gap: 12px; width: 100%;
    padding: 14px 16px; border: 1px solid #e5eef0; border-radius: 10px;
    background: #fff; text-decoration: none; color: inherit; height: 100%;
    transition: border-color .2s ease, background .2s ease, box-shadow .2s ease;
}
.saved-kpi:hover { border-color: #5bc4c7; background: #f0fbfb; color: inherit; }
.saved-kpi.is-active {
    border-color: #185054;
    background: #e6f5f5;
    box-shadow: 0 0 0 1px rgba(24, 80, 84, 0.12);
}
.saved-kpi .kpi-icon {
    width: 40px; height: 40px; border-radius: 10px; display: flex;
    align-items: center; justify-content: center; color: #fff; flex-shrink: 0;
}
.saved-kpi .kpi-icon--heart { background: linear-gradient(135deg, #f87171, #dc2626); }
.saved-kpi .kpi-icon--ban { background: linear-gradient(135deg, #94a3b8, #475569); }
.saved-kpi .kpi-label { font-size: 12px; color: #6b7280; display: block; }
.saved-kpi .kpi-value { font-size: 1.35rem; font-weight: 700; color: #185054; line-height: 1.1; }

.saved-tabs {
    display: flex; gap: 8px; flex-wrap: wrap;
    border-bottom: 1px solid #e5e7eb; margin-bottom: 0;
}
.saved-tab {
    appearance: none; border: 0; background: transparent;
    padding: 10px 14px; font-weight: 600; font-size: 14px; color: #64748b;
    border-bottom: 2px solid transparent; margin-bottom: -1px;
    text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
}
.saved-tab:hover { color: #185054; }
.saved-tab.is-active { color: #185054; border-bottom-color: #185054; }
.saved-tab .count-pill {
    min-width: 22px; height: 22px; padding: 0 7px; border-radius: 999px;
    background: #e6f5f5; color: #185054; font-size: 12px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
}

.saved-site-url { font-weight: 600; color: #0f172a; letter-spacing: .01em; }
.saved-metrics {
    display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px;
}
.saved-metrics div { display: flex; flex-direction: column; gap: 2px; }
.saved-metrics span { font-size: 11px; color: #94a3b8; }
.saved-metrics strong { font-size: 13px; color: #0f172a; }

.saved-mobile-card {
    border: 1px solid #e5eef0; border-radius: 12px; padding: 14px;
    background: #fff;
}
.saved-mobile-card + .saved-mobile-card { margin-top: 12px; }

.saved-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }

@media (max-width: 767.98px) {
    .saved-desktop-only { display: none !important; }
}
@media (min-width: 768px) {
    .saved-mobile-only { display: none !important; }
}
</style>

<div class="container-fluid">
    <div class="row mb-4 align-items-end g-3">
        <div class="col-md-8">
            <h2 class="mb-1 fw-semibold">Saved Sites</h2>
            <p class="text-muted mb-0">
                Manage favorites you plan to order and sites you’ve already used (blacklist).
            </p>
        </div>
        <div class="col-md-4 text-md-end">
            <a href="{{ route('advertiser.catalog') }}" class="btn btn-sm btn-primary">
                <i class="fa-solid fa-list me-1"></i> Browse catalog
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <a href="{{ route('advertiser.saved-sites', ['tab' => 'favorites']) }}"
               class="saved-kpi {{ $tab === 'favorites' ? 'is-active' : '' }}">
                <span class="kpi-icon kpi-icon--heart" aria-hidden="true">
                    <i class="fa-solid fa-heart"></i>
                </span>
                <span>
                    <span class="kpi-label">Favorites</span>
                    <span class="kpi-value" id="favoritesCountLabel">{{ $favoritesCount }}</span>
                </span>
            </a>
        </div>
        <div class="col-md-6">
            <a href="{{ route('advertiser.saved-sites', ['tab' => 'blacklist']) }}"
               class="saved-kpi {{ $tab === 'blacklist' ? 'is-active' : '' }}">
                <span class="kpi-icon kpi-icon--ban" aria-hidden="true">
                    <i class="fa-solid fa-ban"></i>
                </span>
                <span>
                    <span class="kpi-label">Blacklisted</span>
                    <span class="kpi-value" id="blacklistCountLabel">{{ $blacklistCount }}</span>
                </span>
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body pb-0">
            <div class="saved-tabs" role="tablist">
                <a href="{{ route('advertiser.saved-sites', ['tab' => 'favorites']) }}"
                   class="saved-tab {{ $tab === 'favorites' ? 'is-active' : '' }}"
                   role="tab" aria-selected="{{ $tab === 'favorites' ? 'true' : 'false' }}">
                    <i class="fa-regular fa-heart" aria-hidden="true"></i>
                    Favorites
                    <span class="count-pill" id="favoritesTabCount">{{ $favoritesCount }}</span>
                </a>
                <a href="{{ route('advertiser.saved-sites', ['tab' => 'blacklist']) }}"
                   class="saved-tab {{ $tab === 'blacklist' ? 'is-active' : '' }}"
                   role="tab" aria-selected="{{ $tab === 'blacklist' ? 'true' : 'false' }}">
                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
                    Blacklist
                    <span class="count-pill" id="blacklistTabCount">{{ $blacklistCount }}</span>
                </a>
            </div>
        </div>

        <div class="card-body p-0">
            @if($tab === 'favorites')
                @if($favorites->isEmpty())
                    <div class="p-4">
                        <x-ui.empty-state
                            icon="fa-heart"
                            title="No favorites yet"
                            message="Heart sites in the catalog to save them here for later ordering."
                            primary-label="Browse catalog"
                            :primary-url="route('advertiser.catalog')"
                        />
                    </div>
                @else
                    {{-- Desktop table --}}
                    <div class="table-responsive saved-desktop-only">
                        <table class="table align-middle mb-0 data-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Site</th>
                                    <th>Category</th>
                                    <th>Traffic</th>
                                    <th>DR</th>
                                    <th>DA</th>
                                    <th>Country</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="favoritesTableBody">
                                @foreach($favorites as $site)
                                    @php
                                        [$maskedHost, $rawHost] = $maskHost($site->site_url);
                                        $country = strtolower((string) ($site->country ?: 'us'));
                                    @endphp
                                    <tr class="saved-row" data-id="{{ $site->id }}" data-list="favorites">
                                        <td>
                                            <div class="saved-site-url">{{ $maskedHost }}</div>
                                            <div class="small text-muted text-truncate" style="max-width:220px;">{{ $site->site_name }}</div>
                                        </td>
                                        <td class="small">{{ $site->category ?: '—' }}</td>
                                        <td>{{ number_format((int) $site->traffic) }}</td>
                                        <td>{{ $site->dr ?? '—' }}</td>
                                        <td>{{ $site->da ?? '—' }}</td>
                                        <td class="small">
                                            {!! getCountryFlag($country) !!} {{ fullCountry($country) }}
                                        </td>
                                        <td>
                                            <div class="saved-actions">
                                                <a href="{{ route('advertiser.catalog', ['site' => $site->id]) }}"
                                                   class="btn btn-sm btn-primary">
                                                    Order · €{{ number_format((float) $site->display_price, 2) }}
                                                </a>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary js-move-blacklist"
                                                        data-id="{{ $site->id }}"
                                                        data-name="{{ $site->site_name }}">
                                                    <i class="fa-solid fa-ban me-1"></i> Block
                                                </button>
                                                <button type="button"
                                                        class="btn btn-sm btn-cta-tertiary js-remove-favorite"
                                                        data-id="{{ $site->id }}"
                                                        data-name="{{ $site->site_name }}">
                                                    Remove
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Mobile cards --}}
                    <div class="p-3 saved-mobile-only" id="favoritesMobileList">
                        @foreach($favorites as $site)
                            @php
                                [$maskedHost] = $maskHost($site->site_url);
                                $country = strtolower((string) ($site->country ?: 'us'));
                            @endphp
                            <div class="saved-mobile-card saved-row" data-id="{{ $site->id }}" data-list="favorites">
                                <div class="d-flex justify-content-between gap-2 mb-2">
                                    <div>
                                        <div class="saved-site-url">{{ $maskedHost }}</div>
                                        <div class="small text-muted">{{ $site->site_name }}</div>
                                    </div>
                                    <div class="fw-semibold text-nowrap">€{{ number_format((float) $site->display_price, 2) }}</div>
                                </div>
                                <div class="saved-metrics mb-3">
                                    <div><span>Traffic</span><strong>{{ number_format((int) $site->traffic) }}</strong></div>
                                    <div><span>DR</span><strong>{{ $site->dr ?? '—' }}</strong></div>
                                    <div><span>DA</span><strong>{{ $site->da ?? '—' }}</strong></div>
                                    <div><span>Country</span><strong>{!! getCountryFlag($country) !!}</strong></div>
                                </div>
                                <div class="saved-actions justify-content-start">
                                    <a href="{{ route('advertiser.catalog', ['site' => $site->id]) }}" class="btn btn-sm btn-primary">Order</a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary js-move-blacklist"
                                            data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">Block</button>
                                    <button type="button" class="btn btn-sm btn-cta-tertiary js-remove-favorite"
                                            data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">Remove</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @else
                @if($blacklist->isEmpty())
                    <div class="p-4">
                        <x-ui.empty-state
                            icon="fa-ban"
                            title="No blacklisted sites"
                            message="Block sites you’ve already ordered so they stay out of the main catalog."
                            primary-label="Browse catalog"
                            :primary-url="route('advertiser.catalog')"
                        />
                    </div>
                @else
                    <div class="table-responsive saved-desktop-only">
                        <table class="table align-middle mb-0 data-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Site</th>
                                    <th>Category</th>
                                    <th>Traffic</th>
                                    <th>DR</th>
                                    <th>DA</th>
                                    <th>Country</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="blacklistTableBody">
                                @foreach($blacklist as $site)
                                    @php
                                        [$maskedHost] = $maskHost($site->site_url);
                                        $country = strtolower((string) ($site->country ?: 'us'));
                                    @endphp
                                    <tr class="saved-row" data-id="{{ $site->id }}" data-list="blacklist">
                                        <td>
                                            <div class="saved-site-url">{{ $maskedHost }}</div>
                                            <div class="small text-muted text-truncate" style="max-width:220px;">{{ $site->site_name }}</div>
                                        </td>
                                        <td class="small">{{ $site->category ?: '—' }}</td>
                                        <td>{{ number_format((int) $site->traffic) }}</td>
                                        <td>{{ $site->dr ?? '—' }}</td>
                                        <td>{{ $site->da ?? '—' }}</td>
                                        <td class="small">
                                            {!! getCountryFlag($country) !!} {{ fullCountry($country) }}
                                        </td>
                                        <td>
                                            <div class="saved-actions">
                                                <button type="button"
                                                        class="btn btn-sm btn-primary js-move-favorite"
                                                        data-id="{{ $site->id }}"
                                                        data-name="{{ $site->site_name }}">
                                                    <i class="fa-regular fa-heart me-1"></i> Favorite
                                                </button>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary js-remove-blacklist"
                                                        data-id="{{ $site->id }}"
                                                        data-name="{{ $site->site_name }}">
                                                    Unblock
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3 saved-mobile-only" id="blacklistMobileList">
                        @foreach($blacklist as $site)
                            @php
                                [$maskedHost] = $maskHost($site->site_url);
                                $country = strtolower((string) ($site->country ?: 'us'));
                            @endphp
                            <div class="saved-mobile-card saved-row" data-id="{{ $site->id }}" data-list="blacklist">
                                <div class="d-flex justify-content-between gap-2 mb-2">
                                    <div>
                                        <div class="saved-site-url">{{ $maskedHost }}</div>
                                        <div class="small text-muted">{{ $site->site_name }}</div>
                                    </div>
                                    <div class="fw-semibold text-nowrap">€{{ number_format((float) $site->display_price, 2) }}</div>
                                </div>
                                <div class="saved-metrics mb-3">
                                    <div><span>Traffic</span><strong>{{ number_format((int) $site->traffic) }}</strong></div>
                                    <div><span>DR</span><strong>{{ $site->dr ?? '—' }}</strong></div>
                                    <div><span>DA</span><strong>{{ $site->da ?? '—' }}</strong></div>
                                    <div><span>Country</span><strong>{!! getCountryFlag($country) !!}</strong></div>
                                </div>
                                <div class="saved-actions justify-content-start">
                                    <button type="button" class="btn btn-sm btn-primary js-move-favorite"
                                            data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">Favorite</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary js-remove-blacklist"
                                            data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">Unblock</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>

<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
    let favoritesCount = {{ (int) $favoritesCount }};
    let blacklistCount = {{ (int) $blacklistCount }};

    function toast(message, type) {
        if (typeof showToast === 'function') {
            showToast(message, type || 'success');
            return;
        }
        alert(message);
    }

    function updateCounts() {
        const favLabel = document.getElementById('favoritesCountLabel');
        const banLabel = document.getElementById('blacklistCountLabel');
        const favTab = document.getElementById('favoritesTabCount');
        const banTab = document.getElementById('blacklistTabCount');
        if (favLabel) favLabel.textContent = String(favoritesCount);
        if (banLabel) banLabel.textContent = String(blacklistCount);
        if (favTab) favTab.textContent = String(favoritesCount);
        if (banTab) banTab.textContent = String(blacklistCount);
    }

    function removeRows(siteId) {
        document.querySelectorAll('.saved-row[data-id="' + siteId + '"]').forEach((el) => {
            el.style.transition = 'opacity 0.25s ease';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 250);
        });
    }

    function maybeShowEmpty(list) {
        const remaining = document.querySelectorAll('.saved-row[data-list="' + list + '"]').length;
        if (remaining > 0) return;
        // Reload so the empty-state component renders cleanly
        window.location.reload();
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.message || data.error || 'Request failed');
        }
        return data;
    }

    document.querySelectorAll('.js-remove-favorite').forEach((btn) => {
        btn.addEventListener('click', async function () {
            const id = parseInt(this.dataset.id, 10);
            const name = this.dataset.name || 'Site';
            this.disabled = true;
            try {
                const data = await postJson('{{ route('advertiser.saved-sites.favorites.remove') }}', { site_id: id });
                favoritesCount = typeof data.count === 'number' ? data.count : Math.max(0, favoritesCount - 1);
                updateCounts();
                removeRows(id);
                toast(name + ' removed from favorites', 'warning');
                setTimeout(() => maybeShowEmpty('favorites'), 300);
            } catch (err) {
                this.disabled = false;
                toast(err.message || 'Could not remove favorite', 'error');
            }
        });
    });

    document.querySelectorAll('.js-remove-blacklist').forEach((btn) => {
        btn.addEventListener('click', async function () {
            const id = parseInt(this.dataset.id, 10);
            const name = this.dataset.name || 'Site';
            this.disabled = true;
            try {
                const data = await postJson('{{ route('advertiser.saved-sites.blacklist.remove') }}', { site_id: id });
                blacklistCount = typeof data.count === 'number' ? data.count : Math.max(0, blacklistCount - 1);
                updateCounts();
                removeRows(id);
                toast(name + ' unblocked — it will show in the catalog again', 'success');
                setTimeout(() => maybeShowEmpty('blacklist'), 300);
            } catch (err) {
                this.disabled = false;
                toast(err.message || 'Could not unblock site', 'error');
            }
        });
    });

    document.querySelectorAll('.js-move-blacklist').forEach((btn) => {
        btn.addEventListener('click', async function () {
            const id = parseInt(this.dataset.id, 10);
            const name = this.dataset.name || 'Site';
            this.disabled = true;
            try {
                const data = await postJson('{{ route('advertiser.saved-sites.move.blacklist') }}', { site_id: id });
                favoritesCount = typeof data.favorites_count === 'number' ? data.favorites_count : Math.max(0, favoritesCount - 1);
                blacklistCount = typeof data.blacklist_count === 'number' ? data.blacklist_count : blacklistCount + 1;
                updateCounts();
                removeRows(id);
                toast(name + ' moved to blacklist', 'warning');
                setTimeout(() => maybeShowEmpty('favorites'), 300);
            } catch (err) {
                this.disabled = false;
                toast(err.message || 'Could not block site', 'error');
            }
        });
    });

    document.querySelectorAll('.js-move-favorite').forEach((btn) => {
        btn.addEventListener('click', async function () {
            const id = parseInt(this.dataset.id, 10);
            const name = this.dataset.name || 'Site';
            this.disabled = true;
            try {
                const data = await postJson('{{ route('advertiser.saved-sites.move.favorites') }}', { site_id: id });
                favoritesCount = typeof data.favorites_count === 'number' ? data.favorites_count : favoritesCount + 1;
                blacklistCount = typeof data.blacklist_count === 'number' ? data.blacklist_count : Math.max(0, blacklistCount - 1);
                updateCounts();
                removeRows(id);
                toast(name + ' moved to favorites', 'success');
                setTimeout(() => maybeShowEmpty('blacklist'), 300);
            } catch (err) {
                this.disabled = false;
                toast(err.message || 'Could not favorite site', 'error');
            }
        });
    });
})();
</script>
@endsection
