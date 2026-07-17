@php
    $selectedCountry = $selectedCountry ?? old('country', '');
    $billingCountries = \App\Models\Country::marketplace()->orderBy('name')->get(['code', 'name']);
@endphp
<option value="">Select Country</option>
@foreach($billingCountries as $country)
    <option value="{{ $country->name }}" @selected($selectedCountry === $country->name || $selectedCountry === $country->code)>
        {{ $country->name }}
    </option>
@endforeach
