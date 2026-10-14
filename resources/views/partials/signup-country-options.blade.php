@php
    $signupCountries = $signupCountries ?? \App\Support\SignupCountry::options();
    $selectedCountry = \App\Support\SignupCountry::normalize($selectedCountry ?? null);
@endphp
<option value="">Select your country</option>
@foreach($signupCountries as $row)
    <option value="{{ $row['code'] }}" @selected($selectedCountry === $row['code'])>{{ $row['name'] }}</option>
@endforeach
