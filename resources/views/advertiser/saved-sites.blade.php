@extends('advertiser.layouts.app')

@push('page-styles')
<link href="{{ asset('assets/css/saved-sites.css') }}?v={{ @filemtime(public_path('assets/css/saved-sites.css')) ?: '1' }}" rel="stylesheet">
@endpush

@section('content')
@php
    $tab = $tab ?? 'favorites';
    $favorites = $favorites ?? collect();
    $blacklist = $blacklist ?? collect();
    $favoritesCount = $favoritesCount ?? $favorites->count();
    $blacklistCount = $blacklistCount ?? $blacklist->count();
@endphp

<div class="container-fluid saved-page">
    <div class="saved-page-header">
        <div>
            <h2 class="saved-page-title">Saved Sites</h2>
            <p class="saved-page-sub">
                Favorites you plan to order, and sites you’ve blocked from the catalog.
            </p>
        </div>
        <a href="{{ route('advertiser.catalog') }}" class="btn btn-sm btn-primary saved-browse">
            Browse catalog
        </a>
    </div>

    <div class="saved-card">
        <div class="saved-tabs" role="tablist">
            <a href="{{ route('advertiser.saved-sites', ['tab' => 'favorites']) }}"
               class="saved-tab {{ $tab === 'favorites' ? 'is-active' : '' }}"
               role="tab" aria-selected="{{ $tab === 'favorites' ? 'true' : 'false' }}">
                Favorites
                <span class="count-pill" id="favoritesTabCount">{{ $favoritesCount }}</span>
            </a>
            <a href="{{ route('advertiser.saved-sites', ['tab' => 'blacklist']) }}"
               class="saved-tab {{ $tab === 'blacklist' ? 'is-active' : '' }}"
               role="tab" aria-selected="{{ $tab === 'blacklist' ? 'true' : 'false' }}">
                Blacklist
                <span class="count-pill" id="blacklistTabCount">{{ $blacklistCount }}</span>
            </a>
        </div>

        <div>
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
                                        $country = strtolower((string) ($site->country ?: 'us'));
                                    @endphp
                                    <tr class="saved-row" data-id="{{ $site->id }}" data-list="favorites">
                                        <td>
                                            <div class="saved-site-url">{{ $site->display_host }}</div>
                                            <div class="small text-muted text-truncate" style="max-width:220px;">{{ $site->display_name }}</div>
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
                                                @if(! empty($site->is_owned_by_me))
                                                    <a href="{{ route('publisher.websites') }}"
                                                       class="btn btn-sm btn-outline-secondary"
                                                       title="{{ \App\Models\Site::cannotOrderOwnListingMessage() }}">
                                                        Your listing · {{ format_money($site->display_price) }}
                                                    </a>
                                                @else
                                                    <a href="{{ route('advertiser.catalog', ['site' => $site->id]) }}"
                                                       class="btn btn-sm btn-primary">
                                                        Order · {{ format_money($site->display_price) }}
                                                    </a>
                                                @endif
                                                <button type="button"
                                                        class="saved-action-icon js-move-blacklist"
                                                        data-id="{{ $site->id }}"
                                                        data-name="{{ $site->display_name }}"
                                                        title="Block"
                                                        aria-label="Block {{ $site->display_name }}">
                                                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                                </button>
                                                <button type="button"
                                                        class="saved-action-icon saved-action-icon--danger js-remove-favorite"
                                                        data-id="{{ $site->id }}"
                                                        data-name="{{ $site->display_name }}"
                                                        title="Remove"
                                                        aria-label="Remove {{ $site->display_name }}">
                                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
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
                                $country = strtolower((string) ($site->country ?: 'us'));
                            @endphp
                            <div class="saved-mobile-card saved-row" data-id="{{ $site->id }}" data-list="favorites">
                                <div class="d-flex justify-content-between gap-2 mb-2">
                                    <div>
                                        <div class="saved-site-url">{{ $site->display_host }}</div>
                                        <div class="small text-muted">{{ $site->display_name }}</div>
                                    </div>
                                    <div class="fw-semibold text-nowrap">{{ format_money($site->display_price) }}</div>
                                </div>
                                <div class="saved-metrics mb-3">
                                    <div><span>Traffic</span><strong>{{ number_format((int) $site->traffic) }}</strong></div>
                                    <div><span>DR</span><strong>{{ $site->dr ?? '—' }}</strong></div>
                                    <div><span>DA</span><strong>{{ $site->da ?? '—' }}</strong></div>
                                    <div><span>Country</span><strong>{!! getCountryFlag($country) !!}</strong></div>
                                </div>
                                <div class="saved-actions justify-content-start">
                                    @if(! empty($site->is_owned_by_me))
                                        <a href="{{ route('publisher.websites') }}"
                                           class="btn btn-sm btn-outline-secondary"
                                           title="{{ \App\Models\Site::cannotOrderOwnListingMessage() }}">Your listing</a>
                                    @else
                                        <a href="{{ route('advertiser.catalog', ['site' => $site->id]) }}" class="btn btn-sm btn-primary">Order</a>
                                    @endif
                                    <button type="button" class="saved-action-icon js-move-blacklist"
                                            data-id="{{ $site->id }}" data-name="{{ $site->display_name }}"
                                            title="Block" aria-label="Block {{ $site->display_name }}">
                                        <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="saved-action-icon saved-action-icon--danger js-remove-favorite"
                                            data-id="{{ $site->id }}" data-name="{{ $site->display_name }}"
                                            title="Remove" aria-label="Remove {{ $site->display_name }}">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                    </button>
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
                                        $country = strtolower((string) ($site->country ?: 'us'));
                                    @endphp
                                    <tr class="saved-row" data-id="{{ $site->id }}" data-list="blacklist">
                                        <td>
                                            <div class="saved-site-url">{{ $site->display_host }}</div>
                                            <div class="small text-muted text-truncate" style="max-width:220px;">{{ $site->display_name }}</div>
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
                                                        data-name="{{ $site->display_name }}">
                                                    Favorite
                                                </button>
                                                <button type="button"
                                                        class="saved-action-icon js-remove-blacklist"
                                                        data-id="{{ $site->id }}"
                                                        data-name="{{ $site->display_name }}"
                                                        title="Unblock"
                                                        aria-label="Unblock {{ $site->display_name }}">
                                                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
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
                                $country = strtolower((string) ($site->country ?: 'us'));
                            @endphp
                            <div class="saved-mobile-card saved-row" data-id="{{ $site->id }}" data-list="blacklist">
                                <div class="d-flex justify-content-between gap-2 mb-2">
                                    <div>
                                        <div class="saved-site-url">{{ $site->display_host }}</div>
                                        <div class="small text-muted">{{ $site->display_name }}</div>
                                    </div>
                                    <div class="fw-semibold text-nowrap">{{ format_money($site->display_price) }}</div>
                                </div>
                                <div class="saved-metrics mb-3">
                                    <div><span>Traffic</span><strong>{{ number_format((int) $site->traffic) }}</strong></div>
                                    <div><span>DR</span><strong>{{ $site->dr ?? '—' }}</strong></div>
                                    <div><span>DA</span><strong>{{ $site->da ?? '—' }}</strong></div>
                                    <div><span>Country</span><strong>{!! getCountryFlag($country) !!}</strong></div>
                                </div>
                                <div class="saved-actions justify-content-start">
                                    <button type="button" class="btn btn-sm btn-primary js-move-favorite"
                                            data-id="{{ $site->id }}" data-name="{{ $site->display_name }}">Favorite</button>
                                    <button type="button" class="saved-action-icon js-remove-blacklist"
                                            data-id="{{ $site->id }}" data-name="{{ $site->display_name }}"
                                            title="Unblock" aria-label="Unblock {{ $site->display_name }}">
                                        <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                    </button>
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
        // showAppToast/showToast ship in every layout; slbAlert owns the fallback.
        if (typeof showToast === 'function') {
            showToast(message, type || 'success');
            return;
        }
        slbAlert({ icon: type === 'error' ? 'error' : 'success', title: message });
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
            const ok = await window.slbConfirm({
                    title: 'Remove from favorites?',
                    text: 'Remove "' + name + '" from your saved favorites?',
                    confirmText: 'Remove',
                    danger: true,
                });
            if (!ok) return;
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
            const ok = await window.slbConfirm({
                    title: 'Unblock site?',
                    text: 'Unblock "' + name + '"? It will show in the catalog again.',
                    confirmText: 'Unblock',
                    icon: 'question',
                });
            if (!ok) return;
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
            const ok = await window.slbConfirm({
                    title: 'Block this site?',
                    text: 'Move "' + name + '" to your blacklist? It will be hidden from the catalog.',
                    confirmText: 'Block site',
                    danger: true,
                });
            if (!ok) return;
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
            const ok = await window.slbConfirm({
                    title: 'Move to favorites?',
                    text: 'Move "' + name + '" from blacklist to favorites?',
                    confirmText: 'Favorite',
                    icon: 'question',
                });
            if (!ok) return;
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
