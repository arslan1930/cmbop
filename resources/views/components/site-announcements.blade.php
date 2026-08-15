@php
    $announcements = collect();
    $trackPromos = $track ?? true;
    try {
        $announcements = app(\App\Services\PromotionService::class)->activeAnnouncements($audience ?? null);
    } catch (\Throwable $e) {
        $announcements = collect();
    }
@endphp

@if($announcements->isNotEmpty())
<link rel="stylesheet" href="{{ asset('assets/css/promotions.css') }}">
<div class="site-announcements" data-audience="{{ $audience ?? 'auto' }}">
    @foreach($announcements as $item)
        <div class="site-announcement site-announcement--{{ scalar_text($item->style) }} site-announcement-type--{{ scalar_text($item->type) }}"
             data-announcement-id="{{ $item->id }}"
             data-announcement-version="{{ (int) ($item->version ?: 1) }}"
             data-type="{{ scalar_text($item->type) }}"
             role="status">
            <div class="site-announcement__inner">
                <div class="site-announcement__icon" aria-hidden="true">
                    <i class="fa {{ $item->typeIcon() }}"></i>
                </div>
                <div class="site-announcement__body">
                    <span class="site-announcement__type">{{ $item->typeLabel() }}</span>
                    <strong class="site-announcement__title">{{ scalar_text($item->title) }}</strong>
                    <span class="site-announcement__message">{{ scalar_text($item->message) }}</span>
                    @if($item->ends_at && in_array($item->type, ['limited_offer', 'discount', 'black_friday', 'offer'], true))
                        <span class="site-announcement__ends">Ends {{ $item->ends_at->format('M j') }}</span>
                    @endif
                    @if($item->cta_url && $item->cta_label)
                        <a class="site-announcement__cta"
                           href="{{ route('announcements.click', $item) }}"
                           @if(!\Illuminate\Support\Str::startsWith((string) $item->cta_url, '/')) rel="noopener noreferrer" @endif
                        >{{ scalar_text($item->cta_label) }}</a>
                    @endif
                </div>
                @if($item->is_dismissible)
                    <button type="button" class="site-announcement__dismiss" aria-label="Dismiss announcement" data-dismiss-announcement="{{ $item->id }}" data-dismiss-version="{{ (int) ($item->version ?: 1) }}">
                        <i class="fa fa-times"></i>
                    </button>
                @endif
            </div>
        </div>
    @endforeach
</div>
<script>
(function () {
    const key = 'dismissed_announcements';
    let dismissed = [];
    try { dismissed = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { dismissed = []; }
    document.querySelectorAll('[data-announcement-id]').forEach(function (el) {
        const id = parseInt(el.getAttribute('data-announcement-id'), 10);
        const version = parseInt(el.getAttribute('data-announcement-version') || '1', 10);
        const token = id + ':' + version;
        if (dismissed.indexOf(token) !== -1) {
            el.remove();
        }
    });
    document.querySelectorAll('[data-dismiss-announcement]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = parseInt(btn.getAttribute('data-dismiss-announcement'), 10);
            const version = parseInt(btn.getAttribute('data-dismiss-version') || '1', 10);
            const wrap = btn.closest('[data-announcement-id]');
            if (wrap) wrap.remove();
            const token = id + ':' + version;
            if (dismissed.indexOf(token) === -1) {
                dismissed.push(token);
                try { localStorage.setItem(key, JSON.stringify(dismissed)); } catch (e) {}
            }
        });
    });
})();
</script>
@endif
