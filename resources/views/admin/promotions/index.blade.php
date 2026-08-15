@extends(staff_layout())

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Promotions Center</h1>
            <p class="text-muted mb-0">
                Site notices and sized ad banners. Notices do not change catalog prices.
                @if(auth()->user()?->isAdmin())
                    Admins can also toggle the advertiser signup bonus here.
                @endif
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    Preview site
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="{{ staff_route('promotions.preview', ['audience' => 'public']) }}" target="_blank" rel="noopener">As public</a></li>
                    <li><a class="dropdown-item" href="{{ staff_route('promotions.preview', ['audience' => 'advertiser']) }}" target="_blank" rel="noopener">As advertiser</a></li>
                    <li><a class="dropdown-item" href="{{ staff_route('promotions.preview', ['audience' => 'publisher']) }}" target="_blank" rel="noopener">As publisher</a></li>
                </ul>
            </div>
            @if(!empty($announcementsTableReady))
                <a href="{{ staff_route('promotions.announcements.create') }}" class="btn btn-sm btn-primary">
                    <i class="fa fa-bullhorn me-1"></i> New Announcement
                </a>
            @endif
            @if(!empty($bannersTableReady))
                <a href="{{ staff_route('promotions.banners.create') }}" class="btn btn-sm btn-outline-primary">
                    <i class="fa fa-image me-1"></i> New Banner
                </a>
            @endif
        </div>
    </div>

    @include('admin.promotions.partials.undo-bar')

    @if(empty($announcementsTableReady) || empty($bannersTableReady) || (auth()->user()?->isAdmin() && empty($welcomeBonusTableReady)))
        <div class="alert alert-danger" role="alert">
            Promotions storage is incomplete. Run <code>php artisan ops:production-ready --repair</code> (or migrate). Create/save will fail until then.
        </div>
    @endif

    @php
        $welcomeBonusEnabled = $welcomeBonusEnabled ?? true;
        $welcomeBonusAmount = isset($welcomeBonusAmount) ? (float) $welcomeBonusAmount : 20.0;
        $welcomeBonusEuro = '€'.rtrim(rtrim(number_format($welcomeBonusAmount, 2, '.', ''), '0'), '.');
        $welcomeBonusClaims = $welcomeBonusClaims ?? ['week' => 0, 'total' => 0, 'last' => null];
    @endphp
    @if(auth()->user()?->isAdmin())
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h2 class="h5 mb-0">{{ $welcomeBonusEuro }} welcome credit</h2>
                        @if(empty($welcomeBonusTableReady))
                            <span class="badge bg-warning text-dark">Unknown</span>
                        @elseif($welcomeBonusEnabled)
                            <span class="badge bg-success">Enabled</span>
                        @else
                            <span class="badge bg-secondary">Disabled</span>
                        @endif
                    </div>
                    <p class="text-muted small mb-2">
                        New advertisers receive this spend-only credit once per place (signup IP).
                        Existing bonuses are never removed. Publishers never receive it.
                    </p>
                    <div class="small text-muted">
                        {{ (int) ($welcomeBonusClaims['week'] ?? 0) }} claims this week
                        · {{ (int) ($welcomeBonusClaims['total'] ?? 0) }} all-time
                        @if(!empty($welcomeBonusClaims['last']?->user))
                            · last
                            <a href="{{ route('admin.finance.user', $welcomeBonusClaims['last']->user) }}">
                                {{ scalar_text($welcomeBonusClaims['last']->user->email) }}
                            </a>
                            {{ optional($welcomeBonusClaims['last']->created_at)->format('M j') }}
                        @endif
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-start">
                    @if(!empty($welcomeBonusTableReady))
                        <form method="POST" action="{{ route('admin.promotions.welcome-bonus.amount') }}" class="d-flex gap-2">
                            @csrf
                            <input type="number" name="amount" class="form-control form-control-sm" style="width:7rem"
                                   min="0" max="500" step="0.01" value="{{ number_format($welcomeBonusAmount, 2, '.', '') }}"
                                   aria-label="Welcome bonus amount">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Set amount</button>
                        </form>
                        <form method="POST" action="{{ route('admin.promotions.welcome-bonus.toggle') }}"
                              @if($welcomeBonusEnabled)
                                  data-slb-confirm="Disable the {{ $welcomeBonusEuro }} welcome credit? New advertisers will no longer receive it. Existing bonuses stay."
                                  data-slb-confirm-title="Disable welcome bonus?"
                                  data-slb-confirm-text="Disable"
                                  data-slb-confirm-danger="1"
                              @endif
                        >
                            @csrf
                            <input type="hidden" name="enabled" value="{{ $welcomeBonusEnabled ? '0' : '1' }}">
                            <button type="submit" class="btn btn-sm {{ $welcomeBonusEnabled ? 'btn-outline-danger' : 'btn-primary' }}">
                                {{ $welcomeBonusEnabled ? 'Disable' : 'Enable' }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="row g-3 mb-4">
        @foreach($featuredNotices as $key => $notice)
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="fs-4 mb-1" aria-hidden="true">{{ $notice['emoji'] }}</div>
                                <h5 class="mb-1">{{ $notice['label'] }}</h5>
                            </div>
                            <span class="badge bg-light text-dark">
                                {{ $noticeCounts[$key]['live'] ?? 0 }} live
                            </span>
                        </div>
                        <p class="text-muted small flex-grow-1 mb-2">{{ $notice['description'] }}</p>
                        @if(in_array($key, ['limited_offer'], true))
                            <p class="small text-muted mb-2">This publishes a site notice. It does not change catalog prices.</p>
                        @endif
                        <div class="small text-muted mb-3">
                            Example: “{{ $notice['default_title'] }}”
                        </div>
                        @if(!empty($announcementsTableReady))
                            <a href="{{ staff_route('promotions.announcements.create', ['preset' => $key]) }}"
                               class="btn btn-sm {{ $key === 'maintenance' ? 'btn-outline-warning' : ($key === 'new_feature' ? 'btn-outline-success' : 'btn-primary') }}">
                                <i class="fa {{ $notice['icon'] }} me-1"></i> Create {{ $notice['label'] }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <p class="small text-muted mb-3">Only the top {{ (int) config('promotions.max_live_announcements', 2) }} notices and {{ (int) config('promotions.banners_per_placement', 1) }} banner per slot are shown on the site.</p>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Live Announcements</div>
                    <h3 class="mb-0">{{ $stats['announcements_live'] }}</h3>
                    <div class="small text-muted mt-1">
                        <a href="{{ staff_route('promotions.announcements.index') }}">{{ $stats['announcements_total'] }} total</a>
                        · <a href="{{ staff_route('promotions.announcements.index', ['status' => 'scheduled']) }}">{{ $stats['upcoming_announcements'] }} scheduled</a>
                        · <a href="{{ staff_route('promotions.announcements.index', ['status' => 'expired']) }}">{{ $stats['announcements_expired'] ?? 0 }} expired</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Live Banners</div>
                    <h3 class="mb-0">{{ $stats['banners_live'] }}</h3>
                    <div class="small text-muted mt-1">
                        {{ $stats['banners_total'] }} total
                        · <a href="{{ staff_route('promotions.banners.index', ['status' => 'expired']) }}">{{ $stats['banners_expired'] ?? 0 }} expired</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Banner impressions (7d)</div>
                    <h3 class="mb-0">{{ number_format($stats['banner_impressions_7d'] ?? 0) }}</h3>
                    <div class="small text-muted mt-1">{{ number_format($stats['banner_impressions']) }} all-time</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Banner CTR (7d)</div>
                    <h3 class="mb-0">{{ number_format($stats['banner_ctr_7d'] ?? 0, 2) }}%</h3>
                    <div class="small text-muted mt-1">
                        {{ number_format($stats['banner_clicks_7d'] ?? 0) }} clicks / 7d
                        · {{ number_format($stats['announcement_clicks_7d'] ?? 0) }} notice CTA clicks
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-bullhorn me-2 text-primary"></i>Recent Announcements</strong>
                    <a href="{{ staff_route('promotions.announcements.index') }}" class="btn btn-sm btn-outline-secondary">Manage all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Audience</th>
                                    <th>Schedule</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($announcements as $item)
                                    <tr>
                                        <td>
                                            <a href="{{ staff_route('promotions.announcements.edit', $item) }}" class="text-decoration-none">
                                                {{ \Illuminate\Support\Str::limit(scalar_text($item->title), 40) }}
                                            </a>
                                        </td>
                                        <td><span class="badge bg-light text-dark">{{ $item->typeLabel() }}</span></td>
                                        <td class="small text-muted">{{ scalar_text(config('promotions.audiences.'.scalar_text($item->audience), $item->audience)) }}</td>
                                        <td class="small text-muted">@include('admin.promotions.partials.schedule', ['item' => $item])</td>
                                        <td>@include('admin.promotions.partials.status-badge', ['item' => $item])</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No announcements yet. Create a site notice (maintenance, feature, or offer copy).</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-image me-2 text-primary"></i>Recent Banners</strong>
                    <a href="{{ staff_route('promotions.banners.index') }}" class="btn btn-sm btn-outline-secondary">Manage all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Size</th>
                                    <th>Schedule</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($banners as $banner)
                                    <tr>
                                        <td>
                                            <a href="{{ staff_route('promotions.banners.edit', $banner) }}" class="text-decoration-none">
                                                {{ \Illuminate\Support\Str::limit(scalar_text($banner->name), 28) }}
                                            </a>
                                            <div class="small text-muted">{{ $banner->placementLabel() }}</div>
                                        </td>
                                        <td class="small">{{ $banner->width }}×{{ $banner->height }}</td>
                                        <td class="small text-muted">@include('admin.promotions.partials.schedule', ['item' => $banner])</td>
                                        <td>@include('admin.promotions.partials.status-badge', ['item' => $banner])</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No banners yet. Upload a size that fits your layout.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0">
            <strong><i class="fa fa-store me-2 text-primary"></i>Marketplace promotions</strong>
            <div class="small text-muted">
                {{ (int) ($stats['featured_live'] ?? 0) }} featured
                · {{ (int) ($stats['custom_discounts_live'] ?? 0) }} custom sales
                · {{ (int) ($stats['bulk_discounts_live'] ?? 0) }} bulk discounts.
                Publishers set these on their sites — this list is read-only.
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Site</th>
                            <th>Type</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($featuredSites->concat($customDiscountSites)->concat($bulkDiscountSites)->unique('id')->take(12) as $site)
                            <tr>
                                <td>
                                    <a href="{{ staff_route('sites.edit', $site->id) }}">{{ scalar_text($site->site_name ?: $site->url) }}</a>
                                </td>
                                <td class="small">
                                    @if($site->isFeatured()) <span class="badge bg-warning text-dark">Featured</span> @endif
                                    @if($site->hasActiveCustomDiscount()) <span class="badge bg-success">Sale</span> @endif
                                    @if($site->joinsBulkDiscount()) <span class="badge bg-info text-dark">Bulk</span> @endif
                                </td>
                                <td class="small text-muted">
                                    @if($site->isFeatured()) until {{ optional($site->featured_until)->format('M j') }} @endif
                                    @if($site->hasActiveCustomDiscount()) −{{ rtrim(rtrim(number_format((float) $site->custom_discount_percent, 2), '0'), '.') }}% @endif
                                    @if($site->joinsBulkDiscount()) pack −{{ rtrim(rtrim(number_format((float) $site->bulk_discount_percent, 2), '0'), '.') }}% @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No live marketplace promotions. Publishers set these on their sites — this list is read-only.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0">
            <strong><i class="fa fa-ruler-combined me-2 text-primary"></i>Available Banner Sizes</strong>
            <div class="small text-muted">Use these presets so ads fit header, sidebar, marketplace, and mobile slots.</div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach($sizes as $key => $size)
                    <div class="col-6 col-md-4 col-xl-3">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="fw-semibold">{{ $size['label'] }}</div>
                            <div class="text-muted small">
                                @if($key === 'custom')
                                    Custom width × height
                                @else
                                    {{ $size['width'] }}×{{ $size['height'] }} px
                                @endif
                            </div>
                            <div class="small mt-1">{{ $size['hint'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
