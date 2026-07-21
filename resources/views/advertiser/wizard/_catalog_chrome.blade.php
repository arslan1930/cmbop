@php
    $wizardState = \App\Http\Controllers\Advertiser\GuestPostWizardController::stateFromSession();
    $cart = session('cart', []);
    $cartCount = (int) array_sum(array_map(fn ($row) => (int) ($row['quantity'] ?? 0), $cart));
    $lang = strtoupper((string) ($wizardState['language'] ?? ''));
    $cats = $wizardState['categories'] ?? [];
@endphp
<div class="wizard-chrome">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <h2 class="h5 mb-1">Place a guest post · Step 2</h2>
            <p class="muted mb-0">
                Choose publishers
                @if($lang)
                    for <strong>{{ $lang }}</strong>
                @endif
                @if(!empty($cats))
                    · niches: {{ implode(', ', array_slice($cats, 0, 3)) }}{{ count($cats) > 3 ? '…' : '' }}
                @endif
                . Add sites to your cart, then continue.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('advertiser.wizard.market') }}" class="btn btn-sm btn-outline-secondary">Change market</a>
            <a href="{{ route('advertiser.wizard.content') }}"
               id="wizardContinueContent"
               class="btn btn-sm btn-primary">
                Continue to content <span id="wizardCartCountLabel">({{ $cartCount }})</span>
            </a>
            <form method="POST" action="{{ route('advertiser.wizard.exit') }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-link text-muted">Exit guided flow</button>
            </form>
        </div>
    </div>
    @include('advertiser.wizard._stepper', ['step' => 2])
</div>
