@php
    $cartCount = 0;
    $lang = '';
    $cats = [];
    try {
        $wizardState = \App\Http\Controllers\Advertiser\GuestPostWizardController::stateFromSession();
        $cart = is_array($cart ?? null) ? $cart : session('cart', []);
        if (! is_array($cart)) {
            $cart = [];
        }
        $cart = array_values(array_filter($cart, 'is_array'));
        $cartCount = (int) array_sum(array_map(fn ($row) => (int) ($row['quantity'] ?? 0), $cart));
        $langRaw = $wizardState['language'] ?? '';
        $lang = is_scalar($langRaw) ? strtoupper(trim((string) $langRaw)) : '';
        $cats = is_array($wizardState['categories'] ?? null) ? $wizardState['categories'] : [];
        $cats = array_values(array_filter(
            $cats,
            static fn ($c) => is_scalar($c) && trim((string) $c) !== ''
        ));
        $cats = array_map(static fn ($c) => (string) $c, $cats);
    } catch (\Throwable $e) {
        report($e);
        $cart = [];
        $cartCount = 0;
        $lang = '';
        $cats = [];
    }
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
                . Add sites to your cart anytime — unfinished items stay in the cart until you pay.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('advertiser.wizard.market') }}" class="btn btn-sm btn-outline-secondary">Change market</a>
            @if($cartCount > 0)
                <button type="button" class="catalog-plain-action" onclick="openCart()">
                    <i class="fa fa-shopping-cart" aria-hidden="true"></i> Open cart ({{ $cartCount }})
                </button>
            @endif
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
