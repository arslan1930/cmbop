@extends('advertiser.layouts.app')

@section('title', 'Place a guest post')

@section('content')
<div class="container-fluid">
    <div class="wizard-chrome">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h1>Place a guest post</h1>
                <p class="muted">Step 1 — Choose your market (country, then language). We’ll filter publishers next.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('advertiser.catalog') }}" class="btn btn-sm btn-outline-secondary">Browse catalog</a>
                <a href="{{ route('advertiser.content-library') }}" class="btn btn-sm btn-outline-secondary">Content Library</a>
                <form method="POST" action="{{ route('advertiser.wizard.exit') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-link text-muted">Exit guided flow</button>
                </form>
            </div>
        </div>
    </div>

    @include('advertiser.wizard._stepper', ['step' => 1])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('advertiser.wizard.market.save') }}" class="card border-0 shadow-sm">
                @csrf
                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="wizardCountry">Country <span class="text-danger">*</span></label>
                        <select name="country" id="wizardCountry" class="form-select" required>
                            <option value="">Select country</option>
                            @foreach(($countries ?? []) as $country)
                                <option value="{{ strtolower($country->code) }}"
                                    @selected(old('country', $state['country'] ?? '') === strtolower($country->code))>
                                    {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Pick the market first. Language options follow the country pair rules.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="wizardLanguage">Language <span class="text-danger">*</span></label>
                        <select name="language" id="wizardLanguage" class="form-select" required disabled>
                            <option value="">Select country first</option>
                        </select>
                        <div class="form-text">Germany → German only. Gulf markets → Arabic or English.</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Niche / category <span class="text-muted fw-normal">(optional)</span></label>
                        <p class="small text-muted mb-2">Pick topics to narrow the publisher list. Leave empty to see all niches.</p>
                        <div class="row g-2" style="max-height:260px; overflow:auto; border:1px solid #e5e7eb; border-radius:10px; padding:12px;">
                            @php $selectedCats = old('categories', $state['categories'] ?? []); @endphp
                            @foreach($categories as $cat)
                                <div class="col-md-6">
                                    <label class="form-check small mb-1">
                                        <input type="checkbox" class="form-check-input" name="categories[]" value="{{ $cat }}"
                                            @checked(in_array($cat, $selectedCats, true))>
                                        <span class="form-check-label">{{ $cat }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Next: choose publishers in the catalog</span>
                    <button type="submit" class="btn btn-primary">
                        Continue to publishers <i class="fa fa-arrow-right ms-1"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const map = @json($countryLanguageMap ?? new \stdClass());
    const countryEl = document.getElementById('wizardCountry');
    const langEl = document.getElementById('wizardLanguage');
    const preferredLang = @json(old('language', $state['language'] ?? ''));

    function refreshLanguages() {
        const code = (countryEl.value || '').toLowerCase();
        const list = map[code] || [];
        langEl.innerHTML = '';
        if (!code) {
            langEl.disabled = true;
            langEl.innerHTML = '<option value="">Select country first</option>';
            return;
        }
        langEl.disabled = false;
        langEl.innerHTML = '<option value="">Select language</option>';
        list.forEach((row) => {
            const opt = document.createElement('option');
            opt.value = row.code;
            opt.textContent = row.name;
            if (preferredLang && preferredLang === row.code) opt.selected = true;
            langEl.appendChild(opt);
        });
        if (list.length === 1) {
            langEl.value = list[0].code;
        }
    }

    countryEl.addEventListener('change', function () {
        refreshLanguages();
    });
    refreshLanguages();
})();
</script>
@endsection
