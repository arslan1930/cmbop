@php
    $announcements = collect();
    try {
        $announcements = app(\App\Services\PromotionService::class)->activeAnnouncements($audience ?? null);
    } catch (\Throwable $e) {
        $announcements = collect();
    }
@endphp

@if($announcements->isNotEmpty())
<link rel="stylesheet" href="{{ asset('css/promotions.css') }}">
<div class="site-announcements" data-audience="{{ $audience ?? 'auto' }}">
    @foreach($announcements as $item)
        <div class="site-announcement site-announcement--{{ $item->style }} site-announcement-type--{{ $item->type }}"
             data-announcement-id="{{ $item->id }}"
             data-type="{{ $item->type }}"
             role="status">
            <div class="site-announcement__inner">
                <div class="site-announcement__icon" aria-hidden="true">
                    <i class="fa {{ $item->typeIcon() }}"></i>
                </div>
                <div class="site-announcement__body">
                    <span class="site-announcement__type">{{ $item->typeLabel() }}</span>
                    <strong class="site-announcement__title">{{ $item->title }}</strong>
                    <span class="site-announcement__message">{{ $item->message }}</span>
                    @if($item->ends_at && in_array($item->type, ['limited_offer', 'discount', 'black_friday', 'offer'], true))
                        <span class="site-announcement__ends">Ends {{ $item->ends_at->format('M j') }}</span>
                    @endif
                    @if($item->cta_url && $item->cta_label)
                        <a class="site-announcement__cta" href="{{ $item->cta_url }}">{{ $item->cta_label }}</a>
                    @endif
                </div>
                @if($item->is_dismissible)
                    <button type="button" class="site-announcement__dismiss" aria-label="Dismiss announcement" data-dismiss-announcement="{{ $item->id }}">
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
        if (dismissed.indexOf(id) !== -1) {
            el.remove();
        }
    });
    document.querySelectorAll('[data-dismiss-announcement]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = parseInt(btn.getAttribute('data-dismiss-announcement'), 10);
            const wrap = btn.closest('[data-announcement-id]');
            if (wrap) wrap.remove();
            if (dismissed.indexOf(id) === -1) {
                dismissed.push(id);
                try { localStorage.setItem(key, JSON.stringify(dismissed)); } catch (e) {}
            }
        });
    });
})();
</script>
@endif
