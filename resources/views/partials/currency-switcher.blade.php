@php
    $currentDisplayCurrency = app(\App\Support\ViewerCountry::class)->displayCurrency();
    $currencyOptions = [
        'EUR' => '€ EUR',
        'GBP' => '£ GBP',
        'USD' => '$ USD',
    ];
    $currentLabel = $currencyOptions[$currentDisplayCurrency] ?? ($currentDisplayCurrency ?: 'EUR');
@endphp
@auth
    <span class="slb-currency-label d-inline-flex align-items-center" aria-label="Display currency {{ $currentLabel }}" title="Display currency (saved on your account)">{{ $currentLabel }}</span>
@else
<form method="POST" action="{{ route('display-currency') }}" class="slb-currency-switcher d-inline-flex align-items-center gap-1 mb-0" aria-label="Display currency">
    @csrf
    <label for="slb-display-currency" class="visually-hidden">Display currency</label>
    <select name="currency" id="slb-display-currency" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;min-width:5.5rem;">
        @foreach($currencyOptions as $code => $label)
            <option value="{{ $code }}" @selected($currentDisplayCurrency === $code)>{{ $label }}</option>
        @endforeach
    </select>
</form>
@endauth
