@php
    $announcements = collect();
    $trackPromos = $track ?? true;
    $allowedAudiences = array_keys(config('promotions.audiences', []));
    $audienceKey = in_array($audience ?? '', $allowedAudiences, true) ? $audience : null;
    try {
        $announcements = app(\App\Services\PromotionService::class)->activeAnnouncements($audienceKey);
    } catch (\Throwable $e) {
        $announcements = collect();
    }
@endphp

@if($announcements->isNotEmpty())
<link rel="stylesheet" href="{{ asset('assets/css/promotions.css') }}">
<div class="site-announcements" data-audience="{{ $audienceKey ?? 'auto' }}">
    @foreach($announcements as $item)
        <div class="site-announcement site-announcement--{{ $item->styleKey() }} site-announcement-type--{{ $item->typeKey() }}"
             data-announcement-id="{{ $item->id }}"
             data-announcement-version="{{ (int) ($item->version ?: 1) }}"
             data-type="{{ $item->typeKey() }}"
             role="status">
            <div class="site-announcement__inner">
                <div class="site-announcement__icon" aria-hidden="true">
                    <i class="fa {{ $item->typeIcon() }}"></i>
                </div>
                <div class="site-announcement__body">
                    <span class="site-announcement__type">{{ $item->typeLabel() }}</span>
                    <strong class="site-announcement__title">{{ scalar_text($item->title) }}</strong>
                    <span class="site-announcement__message">{{ scalar_text($item->message) }}</span>
                    @if($endsLabel = $item->offerEndsLabel())
                        <span class="site-announcement__ends">Ends {{ $endsLabel }}</span>
                    @endif
                    @if($item->cta_label && $item->clickHref())
                        <a class="site-announcement__cta"
                           href="{{ $trackPromos ? route('announcements.click', $item) : $item->clickHref() }}"
                           @if(!\Illuminate\Support\Str::startsWith((string) $item->clickHref(), '/')) rel="noopener noreferrer" @endif
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
