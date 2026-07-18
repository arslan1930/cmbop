@php
    $step = (int) ($step ?? 1);
    $steps = [
        1 => ['label' => 'Market', 'route' => route('advertiser.wizard.market')],
        2 => ['label' => 'Publishers', 'route' => route('advertiser.wizard.publishers')],
        3 => ['label' => 'Content', 'route' => route('advertiser.wizard.content')],
        4 => ['label' => 'Pay', 'route' => route('advertiser.wizard.pay')],
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
.wizard-step.is-active { border-color:#4ECDCB; background:#f0fbfb; color:#0b6266; }
.wizard-step.is-active .num { background:#0b6266; }
.wizard-step.is-done { border-color:#b8e8e6; background:#e8f8f7; color:#0b6266; }
.wizard-step.is-done .num { background:#3aaeb2; }
.wizard-step.is-disabled { pointer-events:none; opacity:.55; }
.wizard-chrome {
    border:1px solid #b8e8e6; background:linear-gradient(135deg,#f0fbfb,#fff);
    border-radius:14px; padding:14px 16px; margin-bottom:1rem;
}
.wizard-chrome h1, .wizard-chrome h2 { font-size:1.25rem; margin:0 0 4px; color:#0b6266; }
.wizard-chrome .muted { color:#64748b; font-size:.875rem; margin:0; }
</style>
<div class="wizard-stepper" role="navigation" aria-label="Place a guest post steps">
    @foreach($steps as $n => $meta)
        @php
            $isActive = $step === $n;
            $isDone = $step > $n;
            $cls = $isActive ? 'is-active' : ($isDone ? 'is-done' : '');
            // Allow going back to completed/current steps only.
            $canLink = $n <= $step;
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
