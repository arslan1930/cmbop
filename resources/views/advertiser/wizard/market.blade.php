@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="wizard-chrome">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h1>Place a guest post</h1>
                <p class="muted">Step 1 — Choose your market (language and niche). We’ll filter publishers next.</p>
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

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
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
                        <label class="form-label fw-semibold" for="wizardLanguage">Language <span class="text-danger">*</span></label>
                        <select name="language" id="wizardLanguage" class="form-select" required>
                            <option value="">Select language</option>
                            @foreach($languages as $lang)
                                <option value="{{ strtolower($lang->code) }}"
                                    @selected(old('language', $state['language'] ?? '') === strtolower($lang->code))>
                                    {{ $lang->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">English articles work across English-country publishers (US, UK, AU, …).</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="wizardCountry">Preferred country <span class="text-muted fw-normal">(optional)</span></label>
                        <select name="country" id="wizardCountry" class="form-select">
                            <option value="">All countries for this language</option>
                        </select>
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
    const map = @json($languageCountryMap);
    const langEl = document.getElementById('wizardLanguage');
    const countryEl = document.getElementById('wizardCountry');
    const preferred = @json(old('country', $state['country'] ?? ''));

    function refreshCountries() {
        const code = (langEl.value || '').toLowerCase();
        const list = map[code] || [];
        countryEl.innerHTML = '<option value="">All countries for this language</option>';
        list.forEach((row) => {
            const opt = document.createElement('option');
            opt.value = row.code;
            opt.textContent = row.name;
            if (preferred && preferred === row.code) opt.selected = true;
            countryEl.appendChild(opt);
        });
    }

    langEl.addEventListener('change', refreshCountries);
    refreshCountries();
})();
</script>
@endsection
