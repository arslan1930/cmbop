@php
    $step = (int) ($step ?? 1);
    $linkAll = (bool) ($linkAll ?? false);
    $inWizard = request()->boolean('wizard')
        || ! empty(\App\Http\Controllers\Advertiser\GuestPostWizardController::stateFromSession()['language'] ?? null);

    $publisherParams = $inWizard ? array_filter([
        'wizard' => 1,
        'language' => \App\Http\Controllers\Advertiser\GuestPostWizardController::stateFromSession()['language'] ?? null,
    ]) : [];

    $contentRoute = $contentRoute
        ?? ($inWizard
            ? route('advertiser.wizard.content')
            : route('advertiser.content-library'));

    $steps = [
        1 => ['label' => 'Market', 'route' => route('advertiser.wizard.market')],
        2 => ['label' => 'Publishers', 'route' => route('advertiser.catalog', $publisherParams)],
        3 => ['label' => 'Content', 'route' => $contentRoute],
        4 => ['label' => 'Pay', 'route' => route('advertiser.checkout')],
    ];
@endphp
<style>
.wizard-stepper { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:1.25rem; }
.wizard-step {
    display:inline-flex; align-items:center; gap:8px;
    padding:8px 12px; border-radius:10px; border:1px solid #e5e7eb;
    background:#f8fafb; color:#334155; text-decoration:none; font-size:13px; font-weight:600;
}
.wizard-step .num {
    width:22px; height:22px; border-radius:50%; background:#94a3b8; color:#fff;
    display:inline-flex; align-items:center; justify-content:center; font-size:11px;
}
.wizard-step.is-active { border-color:#5bc4c7; background:#f0fbfb; color:#185054; }
.wizard-step.is-active .num { background:#185054; }
.wizard-step.is-done { border-color:#b8e4e4; background:#e6f5f5; color:#185054; }
.wizard-step.is-done .num { background:#3faeb2; }
.wizard-step.is-disabled { pointer-events:none; opacity:.55; }
.wizard-chrome {
    border:1px solid #b8e4e4; background:linear-gradient(135deg,#f0fbfb,#fff);
    border-radius:14px; padding:14px 16px; margin-bottom:1rem;
}
.wizard-chrome h1, .wizard-chrome h2 { font-size:1.25rem; margin:0 0 4px; color:#185054; }
.wizard-chrome .muted { color: var(--brand-ink-muted, #75787B); font-size:.875rem; margin:0; }
.cart-checklist {
    margin:0 0 12px; padding:10px 12px;
    border:1px solid #e5e7eb; border-radius:10px; background:#f8fafb;
}
.cart-checklist ul { list-style:none; margin:0; padding:0; }
.cart-checklist li {
    display:flex; align-items:flex-start; gap:8px;
    font-size:12px; color:#334155; padding:4px 0;
}
.cart-checklist .mark {
    width:16px; height:16px; border-radius:50%; flex-shrink:0;
    display:inline-flex; align-items:center; justify-content:center;
    font-size:10px; margin-top:1px;
}
.cart-checklist .is-ok .mark { background:#185054; color:#fff; }
.cart-checklist .is-todo .mark { background:#fdba74; color:#7c2d12; }
#checkoutFromCart:disabled {
    opacity:.55; cursor:not-allowed;
}
</style>
<div class="wizard-stepper" role="navigation" aria-label="Place a guest post steps">
    @foreach($steps as $n => $meta)
        @php
            $isActive = $step === $n;
            $isDone = $step > $n;
            $cls = $isActive ? 'is-active' : ($isDone ? 'is-done' : '');
            $canLink = $linkAll || $n <= $step;
        @endphp
        @if($canLink)
            <a href="{{ $meta['route'] }}" class="wizard-step {{ $cls }}">
                <span class="num">{{ $n }}</span>
                <span>{{ $meta['label'] }}</span>
            </a>
        @else
            <span class="wizard-step is-disabled {{ $cls }}">
                <span class="num">{{ $n }}</span>
                <span>{{ $meta['label'] }}</span>
            </span>
        @endif
    @endforeach
</div>
