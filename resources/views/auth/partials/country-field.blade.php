@php
    $signupCountries = $signupCountries ?? \App\Support\SignupCountry::options();
    $signupCountry = old('country', $signupCountry ?? null);
    $signupCurrencyPreview = $signupCurrencyPreview ?? \App\Support\SignupCountry::currencyPreview($signupCountry);
@endphp
<div class="mb-3">
    <label for="country" class="auth-label">Country <span class="text-danger">*</span></label>
    <select name="country" id="country" class="form-select auth-input" required aria-describedby="countryError signupCurrencyPreview">
        <option value="">Select your country</option>
        @foreach($signupCountries as $row)
            <option value="{{ $row['code'] }}" @selected($signupCountry === $row['code'])>{{ $row['name'] }}</option>
        @endforeach
    </select>
    <div class="form-text signup-currency-preview" id="signupCurrencyPreview">{{ $signupCurrencyPreview['label'] }}</div>
    <div class="invalid-feedback" id="countryError" role="alert" aria-live="polite">{{ $errors->first('country') }}</div>
</div>
