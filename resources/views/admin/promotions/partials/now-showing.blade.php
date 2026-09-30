@php
    $nowShowing = $nowShowing ?? ['notices' => [], 'banners' => []];
    $audiences = ['public', 'advertiser', 'publisher'];
@endphp
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-1">Now on the site</h2>
        <p class="small text-muted mb-3">
            Same winners as visitors see today: top {{ (int) config('promotions.max_live_announcements', 2) }} notices
            per audience, and {{ (int) config('promotions.banners_per_placement', 1) }} banner per wired slot (daily rotation).
        </p>
        <div class="row g-3">
            @foreach($audiences as $audience)
                @php
                    $notices = $nowShowing['notices'][$audience] ?? collect();
                    $banners = $nowShowing['banners'][$audience] ?? [];
                @endphp
                <div class="col-md-4">
                    <div class="fw-semibold mb-2">{{ scalar_text(config('promotions.audiences.'.$audience, $audience)) }}</div>
                    <div class="small mb-2">
                        <div class="text-muted">Notices</div>
                        @forelse($notices as $item)
                            <div>
                                <a href="{{ staff_route('promotions.announcements.edit', $item) }}">{{ \Illuminate\Support\Str::limit(scalar_text($item->title), 42) }}</a>
                            </div>
                        @empty
                            <div class="text-muted">None showing</div>
                        @endforelse
                    </div>
                    <div class="small">
                        <div class="text-muted">Banners</div>
                        @forelse($banners as $placement => $banner)
                            <div>
                                <span class="text-muted">{{ scalar_text(config('promotions.banner_placements.'.$placement, $placement)) }}:</span>
                                <a href="{{ staff_route('promotions.banners.edit', $banner) }}">{{ \Illuminate\Support\Str::limit(scalar_text($banner->name), 28) }}</a>
                            </div>
                        @empty
                            <div class="text-muted">No wired slot filled</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
