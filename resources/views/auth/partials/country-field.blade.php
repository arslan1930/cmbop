@php
    $signupCountries = $signupCountries ?? \App\Support\SignupCountry::options();
    $signupCountry = old('country', $signupCountry ?? null);
    $signupCurrencyPreview = $signupCurrencyPreview ?? \App\Support\SignupCountry::currencyPreview($signupCountry);
@endphp
<div class="mb-3">
    <label for="country" class="auth-label">Country <span class="text-danger">*</span></label>
    <select name="country" id="country" class="form-select auth-input js-slb-search-select" required autocomplete="country" aria-describedby="countryError signupCurrencyPreview">
        @include('partials.signup-country-options', ['signupCountries' => $signupCountries, 'selectedCountry' => $signupCountry])
    </select>
    <div class="form-text signup-currency-preview" id="signupCurrencyPreview">{{ $signupCurrencyPreview['label'] }}</div>
    <div class="invalid-feedback" id="countryError" role="alert" aria-live="polite">{{ $errors->first('country') }}</div>
</div>
