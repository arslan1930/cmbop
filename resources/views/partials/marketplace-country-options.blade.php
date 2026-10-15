@php
    $selectedCountry = $selectedCountry ?? old('country', '');
    $billingCountries = \App\Support\MarketplacePicker::countries();
@endphp
<option value="">Select Country</option>
@foreach($billingCountries as $country)
    <option value="{{ marketplace_option_name($country) }}" @selected($selectedCountry === marketplace_option_name($country) || $selectedCountry === marketplace_option_code($country))>
        {{ marketplace_option_name($country) }}
    </option>
@endforeach
