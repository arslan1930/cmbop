@php
    $size = $size ?? 'tile';
    $header = $size === 'header';
@endphp
<span class="payment-brand-logos{{ $header ? ' payment-brand-logos--header' : '' }}" aria-hidden="true">
    <img src="{{ asset('assets/img/payments/visa.svg') }}" alt="Visa" width="{{ $header ? 28 : 32 }}" height="{{ $header ? 12 : 16 }}" decoding="async">
    <img src="{{ asset('assets/img/payments/mastercard.svg') }}" alt="Mastercard" width="{{ $header ? 22 : 26 }}" height="{{ $header ? 12 : 16 }}" decoding="async">
</span>
