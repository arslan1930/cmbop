@php
    $usdWireUser = $usdWireUser ?? (auth()->user());
    $usdWireCurrency = $walletCurrency
        ?? (isset($wallet) ? ($wallet->currency ?? null) : null)
        ?? (isset($checkoutWallet) ? ($checkoutWallet->currency ?? null) : null);
    $usdWireAmount = $eurAmount ?? null;
    $usdWire = app(\App\Support\UsdBankWireGuidance::class)->viewPayload(
        $usdWireCurrency,
        $usdWireUser,
        $usdWireAmount
    );
    $usdWireSwitch = $showMethodSwitch ?? true;
    $usdWireSwitchAsLinks = $methodSwitchAsLinks ?? false;
@endphp
@if(!empty($usdWire['show']))
    <div class="js-usd-bank-wire usd-bank-wire-note alert alert-warning mb-3" data-usd-bank-wire="1" role="status">
        <p class="js-usd-bank-send-label fw-semibold mb-2" data-usd-send-label="1">{{ $usdWire['send_label'] }}</p>
        <p class="mb-2" data-usd-wire-note="1">{{ $usdWire['wire_note'] }}</p>
        <p class="mb-0" data-usd-avoid-fees="1">
            {{ $usdWire['avoid_fees'] }}
            @if($usdWireSwitch)
                @if($usdWireSwitchAsLinks)
                    <a class="btn btn-sm btn-outline-primary ms-1" href="{{ route('advertiser.add-funds', ['method' => 'card']) }}">{{ $usdWire['card_label'] }}</a>
                    <a class="btn btn-sm btn-outline-primary ms-1" href="{{ route('advertiser.add-funds', ['method' => 'paypal']) }}">{{ $usdWire['paypal_label'] }}</a>
                @else
                    <button type="button" class="btn btn-sm btn-outline-primary js-switch-deposit-method ms-1" data-method="card">{{ $usdWire['card_label'] }}</button>
                    <button type="button" class="btn btn-sm btn-outline-primary js-switch-deposit-method ms-1" data-method="paypal">{{ $usdWire['paypal_label'] }}</button>
                @endif
            @endif
        </p>
    </div>
@endif
