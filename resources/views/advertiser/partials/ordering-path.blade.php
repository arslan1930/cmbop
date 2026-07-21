@php
    $step = (int) ($step ?? 1);
    $title = $title ?? 'Place a guest post';
    $subtitle = $subtitle ?? 'Market → Publishers → Content → Pay';
    $linkAll = (bool) ($linkAll ?? true);
    $contentRoute = $contentRoute ?? null;
@endphp
<div class="wizard-chrome">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <h2 class="h5 mb-1">{{ $title }}</h2>
            @if($subtitle)
                <p class="muted mb-0">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="d-flex flex-wrap gap-2">
                {!! $actions !!}
            </div>
        @endisset
    </div>
    @include('advertiser.wizard._stepper', [
        'step' => $step,
        'linkAll' => $linkAll,
        'contentRoute' => $contentRoute,
    ])
</div>
