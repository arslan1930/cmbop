@extends(staff_layout())

@section('title', 'Add site for publisher')

@section('content')
@php
    $categories = $categories ?? collect();
    $languages = $languages ?? collect();
    $isMarketingEditor = $isMarketingEditor ?? false;
    $selectedPublisherId = (int) ($selectedPublisherId ?? 0);
    $selectedPublisherUnverified = $selectedPublisherUnverified ?? false;
    $sitesBackUrl = $sitesBackUrl ?? staff_route('sites.index');
    $prefillSiteName = $prefillSiteName ?? '';
    $prefillSiteUrl = $prefillSiteUrl ?? '';
    $prefillCountry = $prefillCountry ?? '';
    $prefillLanguage = $prefillLanguage ?? '';
    $suggestionId = (int) ($suggestionId ?? 0);
    $rawNiches = old('categories', []);
    if (! is_string($rawNiches) && ! is_iterable($rawNiches)) {
        $rawNiches = [];
    }
    if (is_string($rawNiches)) {
        $rawNiches = preg_split('/\|/', $rawNiches) ?: [];
    }
    $prefillNiches = \App\Models\Category::resolveNicheNames($rawNiches)['resolved'] ?? [];
    if (is_string($prefillNiches)) {
        $prefillNiches = array_values(array_filter(array_map('trim', preg_split('/\|/', $prefillNiches) ?: [])));
    }
    $prefillNiches = collect($prefillNiches)
        ->flatten()
        ->filter(fn ($v) => is_scalar($v) && filled($v))
        ->map(fn ($v) => (string) $v)
        ->values()
        ->all();
@endphp
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Add site for publisher</h4>
            <p class="text-muted mb-0 small">
                Create a listing with core details plus optional homepage, social, and sensitive-topic prices. The publisher gets email + bell and must Accept it into My Sites.
                @if($isMarketingEditor)
                    After Accept, Activate makes the listing live and verifies it — and only if DA ≥ {{ \App\Models\Site::GOOD_MIN_DA }}, DR ≥ {{ \App\Models\Site::GOOD_MIN_DR }}, traffic ≥ {{ number_format(\App\Models\Site::GOOD_MIN_TRAFFIC) }}, and a marketplace country is set. The Verify button stays admin-only.
                @else
                    After Accept, Activate makes the listing live and verifies it. You can still Verify without activating. Accept ≠ Verified, and catalog Activate is not automatic.
                @endif
                See the <a href="{{ staff_route('staff-handbook') }}">{{ __('messages.staff_handbook_title') }}</a>.
            </p>
        </div>
        <a href="{{ $sitesBackUrl }}" class="btn btn-sm btn-outline-secondary">← Back to Sites</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ staff_route('sites.store') }}" enctype="multipart/form-data" id="staffAssignSiteForm">
                @csrf
                @if((int) old_text('suggestion_id', $suggestionId) > 0)
                    <input type="hidden" name="suggestion_id" value="{{ (int) old_text('suggestion_id', $suggestionId) }}">
                    <div class="alert alert-info border-0 py-2 px-3 small mb-3">
                        Prefilling from website suggestion #{{ (int) old_text('suggestion_id', $suggestionId) }}. Saving this listing will mark that suggestion accepted.
                    </div>
                @endif

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="publisher_id">Publisher <span class="text-danger">*</span></label>
                        <input type="search" id="publisherFilter" class="form-control mb-2" placeholder="Type to filter publishers…" autocomplete="off" aria-label="Filter publishers">
                        <select id="publisher_id" name="publisher_id" class="form-select @error('publisher_id') is-invalid @enderror" required>
                            <option value="">Select publisher…</option>
                            @foreach($publishers as $publisher)
                                <option value="{{ $publisher->id }}"
                                    data-verified="{{ filled($publisher->email_verified_at) ? '1' : '0' }}"
                                    @selected((int) old_text('publisher_id', $selectedPublisherId) === (int) $publisher->id)>
                                    {{ $publisher->name }} · {{ $publisher->email }}
                                    @if((int) ($publisher->sites_count ?? 0) > 0)
                                        ({{ (int) $publisher->sites_count }} {{ \Illuminate\Support\Str::plural('site', (int) $publisher->sites_count) }})
                                    @endif
                                    @if(blank($publisher->email_verified_at))
                                        · unverified
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Verified-email publishers only. An unverified account from the URL still appears with a warning.</div>
                        <div class="alert alert-warning border-0 py-2 px-3 small mb-0 mt-2 {{ $selectedPublisherUnverified ? '' : 'd-none' }}" id="unverifiedPublisherWarn" role="status">
                            This publisher has not verified their email. They cannot log in to Accept the invite until they verify.
                        </div>
                        @error('publisher_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="site_name">Site name <span class="text-danger">*</span></label>
                        <input type="text" id="site_name" name="site_name" class="form-control @error('site_name') is-invalid @enderror"
                               value="{{ old_text('site_name', $prefillSiteName) }}" required maxlength="255">
                        @error('site_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="site_url">Site URL <span class="text-danger">*</span></label>
                        <input type="text" id="site_url" name="site_url" class="form-control @error('site_url') is-invalid @enderror"
                               value="{{ old_text('site_url', $prefillSiteUrl) }}" required placeholder="https://example.com">
                        @error('site_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="example_url">Example post URL <span class="text-danger">*</span></label>
                        <input type="text" id="example_url" name="example_url" class="form-control @error('example_url') is-invalid @enderror"
                               value="{{ old_text('example_url') }}" required placeholder="https://example.com/sample-post">
                        <div class="form-text">Must be on the same domain as the site URL.</div>
                        @error('example_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="price">Price (€) <span class="text-danger">*</span></label>
                        <input type="number" id="price" name="price" class="form-control @error('price') is-invalid @enderror"
                               min="0" step="0.01" required value="{{ old_text('price') }}">
                        @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="da">DA <span class="text-danger">*</span></label>
                        <input type="number" id="da" name="da" class="form-control @error('da') is-invalid @enderror"
                               min="0" max="100" step="1" inputmode="numeric" required
                               placeholder="0–100" value="{{ old_text('da') }}">
                        <div class="form-text">Domain Authority (0–100). Whole numbers only.</div>
                        @error('da')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="dr">DR <span class="text-danger">*</span></label>
                        <input type="number" id="dr" name="dr" class="form-control @error('dr') is-invalid @enderror"
                               min="0" max="100" step="1" inputmode="numeric" required
                               placeholder="0–100" value="{{ old_text('dr') }}">
                        <div class="form-text">Domain Rating (0–100). Whole numbers only.</div>
                        @error('dr')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="traffic">Traffic <span class="text-danger">*</span></label>
                        <input type="number" id="traffic" name="traffic" class="form-control @error('traffic') is-invalid @enderror"
                               min="0" max="4294967295" step="1" inputmode="numeric" required
                               placeholder="e.g. 1500000" value="{{ old_text('traffic') }}">
                        <div class="form-text">Monthly organic visits (whole number).</div>
                        @error('traffic')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <div class="form-text mb-0" id="qualityBarStatic"
                             data-min-da="{{ \App\Models\Site::GOOD_MIN_DA }}"
                             data-min-dr="{{ \App\Models\Site::GOOD_MIN_DR }}"
                             data-min-traffic="{{ \App\Models\Site::GOOD_MIN_TRAFFIC }}">
                            Marketing Activate needs DA ≥ {{ \App\Models\Site::GOOD_MIN_DA }}, DR ≥ {{ \App\Models\Site::GOOD_MIN_DR }}, and traffic ≥ {{ number_format(\App\Models\Site::GOOD_MIN_TRAFFIC) }}. Saving below this is allowed.
                        </div>
                        <div class="alert alert-warning border-0 py-2 px-3 small d-none mb-0 mt-2" id="qualityBarWarn" role="status">
                            These metrics are below the marketing Activate bar. You can still save — admin must verify, and marketing will not be able to Activate until the bar is met.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="country">Country <span class="text-danger">*</span></label>
                        <select id="country" name="country" class="form-select @error('country') is-invalid @enderror" required>
                            <option value="">Select…</option>
                            @foreach($countries as $country)
                                <option value="{{ strtolower($country->code) }}"
                                    @selected(old_text('country', $prefillCountry) === strtolower($country->code))>
                                    {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Pick country first.</div>
                        @error('country')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="language">Language <span class="text-danger">*</span></label>
                        <input type="hidden" name="language" id="selectedLanguage" value="{{ old_text('language', $prefillLanguage) }}">
                        <select id="language" name="language" class="form-select @error('language') is-invalid @enderror" required>
                            <option value="">{{ old_text('country', $prefillCountry) !== '' ? 'Select…' : 'Select country first' }}</option>
                            @foreach($languages as $language)
                                <option value="{{ strtolower($language->code) }}"
                                    @selected(old_text('language', $prefillLanguage) === strtolower($language->code))>
                                    {{ $language->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Only languages paired with that country.</div>
                        @error('language')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold" for="categoryInput">Niches <span class="text-danger">*</span> (max 7)</label>
                        <input type="hidden" name="categories" id="selectedCategories" value="{{ implode('|', $prefillNiches) }}">
                        <div class="multi-select-wrapper" id="categoryWrapper" data-multi-select="category">
                            <div class="multi-select-input" id="categoryInput" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select niches">
                                <span class="multi-select-placeholder">Select niches (max 7)…</span>
                            </div>
                            <div class="multi-select-dropdown" id="categoryDropdown" role="listbox" aria-multiselectable="true">
                                <div class="multi-select-search">
                                    <input type="text" placeholder="Type to search niches…" id="categorySearch" autocomplete="off" aria-label="Search niches">
                                </div>
                                <div class="multi-select-options" id="categoryOptions">
                                    @foreach($categories as $categoryName)
                                        <div class="multi-select-option"
                                             role="option"
                                             data-value="{{ $categoryName }}"
                                             data-label="{{ $categoryName }}">{{ $categoryName }}</div>
                                    @endforeach
                                </div>
                                <div class="multi-select-empty d-none" id="categoryEmpty" role="status">No categories found</div>
                            </div>
                        </div>
                        <div class="form-text">Click to toggle; type to search; Enter adds the highlighted match. Max 7.</div>
                        @error('categories')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="turnaround_time">Turnaround <span class="text-danger">*</span></label>
                        <select id="turnaround_time" name="turnaround_time" class="form-select @error('turnaround_time') is-invalid @enderror" required>
                            @foreach(['24h' => '24 hours', '48h' => '48 hours', '3days' => '3 days', '5days' => '5 days', '7days' => '7 days'] as $value => $label)
                                <option value="{{ $value }}" @selected(old_text('turnaround_time', '3days') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('turnaround_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="publication_time">Publication time <span class="text-danger">*</span></label>
                        <select id="publication_time" name="publication_time" class="form-select @error('publication_time') is-invalid @enderror" required>
                            @foreach(['6months' => '6 months', '1year' => '1 year', 'permanent' => 'Permanent'] as $value => $label)
                                <option value="{{ $value }}" @selected(old_text('publication_time', 'permanent') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('publication_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="link_type">Link type <span class="text-danger">*</span></label>
                        <select id="link_type" name="link_type" class="form-select @error('link_type') is-invalid @enderror" required>
                            <option value="dofollow" @selected(old_text('link_type', 'dofollow') === 'dofollow')>Dofollow</option>
                            <option value="nofollow" @selected(old_text('link_type') === 'nofollow')>Nofollow</option>
                        </select>
                        @error('link_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold d-block">Site tag</label>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach(\App\Support\SiteTag::staffFormOptions() as $value => $label)
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="site_tag" id="tag_{{ $value }}"
                                           value="{{ $value }}" @checked(old_text('site_tag', 'as_you_prefer') === $value)>
                                    <label class="form-check-label" for="tag_{{ $value }}">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="site_image">Site image</label>
                        <input type="file" id="site_image" name="site_image"
                               class="form-control @error('site_image') is-invalid @enderror"
                               accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                               data-max-kb="{{ \App\Support\SiteImageUpload::maxKilobytes() }}"
                               data-php-max-kb="{{ \App\Support\SiteImageUpload::phpUploadMaxKilobytes() }}">
                        <div class="form-text">Optional desktop screenshot (JPEG, PNG, GIF, or WebP up to {{ \App\Support\SiteImageUpload::maxMegabytesLabel() }}&nbsp;MB).</div>
                        @error('site_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        @include('partials.site-description-editor', [
                            'value' => old_text('description', ''),
                            'required' => true,
                        ])
                    </div>

                    @php
                        $homepageDays = config('site_placement.homepage_days', [1, 7, 30]);
                        $hasSensitiveOld = collect(['crypto','trading','CBD','forex'])->contains(function ($t) {
                            $flag = old("sensitive.$t");
                            $price = old("price_sensitive.$t");
                            return ($flag !== null && $flag !== '' && $flag !== [])
                                || ($price !== null && $price !== '' && $price !== []);
                        });
                    @endphp
                    <div class="col-12">
                        <input type="hidden" name="placement_offers_form" value="1">
                        <div class="border rounded p-3 bg-light">
                            <p class="fw-semibold mb-1">Homepage &amp; social promotions (optional)</p>
                            <p class="small text-muted mb-3">Advertisers see these in catalog Site Details. Leave unchecked to hide the offer.</p>
                            <p class="fw-semibold small mb-2">Homepage placement</p>
                            <div class="d-flex flex-wrap gap-3 mb-3">
                                @foreach($homepageDays as $days)
                                    <div style="min-width:140px;">
                                        <div class="form-check">
                                            <input type="checkbox" name="homepage[{{ $days }}]" value="1"
                                                   class="form-check-input" id="staffHomepage{{ $days }}"
                                                   {{ old("homepage.$days") ? 'checked' : '' }}>
                                            <label class="form-check-label" for="staffHomepage{{ $days }}">{{ $days }} day{{ $days > 1 ? 's' : '' }}</label>
                                        </div>
                                        <input type="number" name="price_homepage[{{ $days }}]" class="form-control mt-1 @error('price_homepage.'.$days) is-invalid @enderror"
                                               placeholder="Fee (€) — 0 = Free" min="0" step="0.01" inputmode="decimal"
                                               value="{{ old_text('price_homepage.'.$days) }}">
                                        @error('price_homepage.'.$days)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endforeach
                            </div>
                            <p class="fw-semibold small mb-2">Social media sharing (always free)</p>
                            <div class="d-flex flex-wrap gap-3">
                                @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X'] as $channel => $label)
                                    <div class="form-check">
                                        <input type="checkbox" name="social[{{ $channel }}]" value="1"
                                               class="form-check-input" id="staffSocial{{ ucfirst($channel) }}"
                                               {{ old("social.$channel") ? 'checked' : '' }}>
                                        <label class="form-check-label" for="staffSocial{{ ucfirst($channel) }}">{{ $label }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="button"
                                class="disclosure-toggle"
                                id="sensitiveDisclosureBtn"
                                aria-expanded="{{ $hasSensitiveOld ? 'true' : 'false' }}"
                                aria-controls="sensitiveDisclosurePanel">
                            <i class="fa fa-chevron-{{ $hasSensitiveOld ? 'down' : 'right' }}" aria-hidden="true"></i>
                            Sensitive topics (optional)
                        </button>
                        <p class="small text-muted mb-0 mt-1">Only open if this publisher accepts crypto, trading, CBD, or forex. Checked + blank extra = €0 surcharge.</p>
                        <div class="disclosure-panel" id="sensitiveDisclosurePanel" @unless($hasSensitiveOld) hidden @endunless>
                            <div class="row bg-light p-3 rounded mt-2">
                                <div class="col-12">
                                    <div class="d-flex flex-wrap gap-3">
                                        @foreach(['crypto','trading','CBD','forex'] as $topic)
                                        <div class="me-3">
                                            <div class="form-check">
                                                <input type="checkbox" name="sensitive[{{ $topic }}]" value="1" class="form-check-input" id="sensitive{{ $topic }}" {{ old("sensitive.$topic") ? 'checked' : '' }}>
                                                <label class="form-check-label" for="sensitive{{ $topic }}">{{ ucfirst($topic) }}</label>
                                            </div>
                                            <input type="number" name="price_sensitive[{{ $topic }}]" class="form-control mt-1 @error('price_sensitive.'.$topic) is-invalid @enderror" placeholder="Extra (€) — 0 = none" value="{{ old_text('price_sensitive.'.$topic) }}" min="0" step="0.01">
                                            @error('price_sensitive.'.$topic)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input @error('written_request') is-invalid @enderror"
                                   type="checkbox" name="written_request" id="written_request" value="1"
                                   @checked(old('written_request')) required>
                            <label class="form-check-label" for="written_request">
                                I have a written request from this publisher’s account email
                            </label>
                        </div>
                        <div class="form-text">Handbook: only after a ticket, email, or in-product chat from that account.</div>
                        @error('written_request')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-plus me-1"></i> Add site &amp; notify publisher
                    </button>
                    <a href="{{ $sitesBackUrl }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<link href="{{ asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
<script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="{{ asset('js/multi-select.js') }}?v={{ @filemtime(public_path('js/multi-select.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/site-image-upload.js') }}?v={{ @filemtime(public_path('assets/js/site-image-upload.js')) ?: '1' }}"></script>
<script>
(function () {
    const map = @json($countryLanguageMap ?? new \stdClass());
    const countryEl = document.getElementById('country');
    const langEl = document.getElementById('language');
    const langHidden = document.getElementById('selectedLanguage');
    const preferredLang = @json(old_text('language', $prefillLanguage));
    const imageInput = document.getElementById('site_image');
    if (imageInput && window.SiteImageUpload) {
        window.SiteImageUpload.bindSiteImageInput({
            input: imageInput,
            onError: function (title) {
                if (window.slbAlert) {
                    window.slbAlert({ icon: 'warning', title: title });
                } else if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: title, timer: 2800, showConfirmButton: false });
                }
            },
        });
    }
    const qualityBar = document.getElementById('qualityBarStatic');
    const qualityWarn = document.getElementById('qualityBarWarn');
    const minDa = parseInt((qualityBar && qualityBar.getAttribute('data-min-da')) || '30', 10);
    const minDr = parseInt((qualityBar && qualityBar.getAttribute('data-min-dr')) || '30', 10);
    const minTraffic = parseInt((qualityBar && qualityBar.getAttribute('data-min-traffic')) || '10000', 10);

    function refreshQualityBar() {
        if (!qualityWarn) return;
        const da = parseInt((document.getElementById('da') || {}).value, 10);
        const dr = parseInt((document.getElementById('dr') || {}).value, 10);
        const traffic = parseInt((document.getElementById('traffic') || {}).value, 10);
        const filled = Number.isFinite(da) && Number.isFinite(dr) && Number.isFinite(traffic);
        const below = filled && (da < minDa || dr < minDr || traffic < minTraffic);
        qualityWarn.classList.toggle('d-none', !below);
    }
    ['da', 'dr', 'traffic'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', refreshQualityBar);
    });
    refreshQualityBar();

    function syncLanguageHidden() {
        if (!langHidden) return;
        if (langEl && !langEl.disabled) {
            langHidden.value = String(langEl.value || '').toLowerCase();
            return;
        }
        langHidden.value = String(langHidden.value || '').toLowerCase();
    }

    function languageValue() {
        if (langEl && !langEl.disabled && String(langEl.value || '').trim()) {
            return String(langEl.value).toLowerCase();
        }
        return String((langHidden && langHidden.value) || '').toLowerCase();
    }

    function refreshLanguages() {
        if (!countryEl || !langEl) return;
        const code = (countryEl.value || '').toLowerCase();
        const list = map[code] || [];
        const keep = (preferredLang || (langHidden && langHidden.value) || langEl.value || '').toLowerCase();
        langEl.innerHTML = '';
        if (!code) {
            langEl.disabled = true;
            langEl.innerHTML = '<option value="">Select country first</option>';
            if (langHidden) langHidden.value = '';
            return;
        }
        langEl.disabled = false;
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select…';
        langEl.appendChild(placeholder);
        list.forEach(function (row) {
            const opt = document.createElement('option');
            opt.value = row.code;
            opt.textContent = row.name || String(row.code).toUpperCase();
            if (keep && keep === String(row.code).toLowerCase()) opt.selected = true;
            langEl.appendChild(opt);
        });
        if (list.length === 1) {
            langEl.value = list[0].code;
        }
        syncLanguageHidden();
    }

    if (countryEl) {
        countryEl.addEventListener('change', function () {
            refreshLanguages();
        });
        refreshLanguages();
    }
    if (langEl) {
        langEl.addEventListener('change', syncLanguageHidden);
    }

    const prefills = @json($prefillNiches);
    const ms = window.initMultiSelect({
        wrapperId: 'categoryWrapper',
        inputId: 'categoryInput',
        dropdownId: 'categoryDropdown',
        optionsId: 'categoryOptions',
        hiddenInputId: 'selectedCategories',
        searchId: 'categorySearch',
        emptyId: 'categoryEmpty',
        maxSelections: 7,
        placeholderText: 'Select niches (max 7)…',
    });
    if (ms && prefills.length) {
        ms.setSelectedItems(prefills, prefills);
    }

    const publisherFilter = document.getElementById('publisherFilter');
    const publisherSelect = document.getElementById('publisher_id');
    const unverifiedWarn = document.getElementById('unverifiedPublisherWarn');
    function refreshUnverifiedPublisherWarn() {
        if (!unverifiedWarn || !publisherSelect) return;
        const selected = publisherSelect.options[publisherSelect.selectedIndex];
        const unverified = !!(selected && selected.value && selected.getAttribute('data-verified') === '0');
        unverifiedWarn.classList.toggle('d-none', !unverified);
    }
    if (publisherFilter && publisherSelect) {
        publisherFilter.addEventListener('input', function () {
            const q = String(publisherFilter.value || '').trim().toLowerCase();
            Array.prototype.forEach.call(publisherSelect.options, function (opt, i) {
                if (i === 0 && !opt.value) {
                    opt.hidden = false;
                    return;
                }
                opt.hidden = q !== '' && !opt.selected && String(opt.textContent || '').toLowerCase().indexOf(q) === -1;
            });
        });
        publisherSelect.addEventListener('change', refreshUnverifiedPublisherWarn);
        refreshUnverifiedPublisherWarn();
    }

    const sensitiveBtn = document.getElementById('sensitiveDisclosureBtn');
    const sensitivePanel = document.getElementById('sensitiveDisclosurePanel');
    if (sensitiveBtn && sensitivePanel) {
        sensitiveBtn.addEventListener('click', function () {
            const open = sensitivePanel.hasAttribute('hidden');
            if (open) {
                sensitivePanel.removeAttribute('hidden');
            } else {
                sensitivePanel.setAttribute('hidden', '');
            }
            sensitiveBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            const icon = sensitiveBtn.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-chevron-right', !open);
                icon.classList.toggle('fa-chevron-down', open);
            }
        });
    }

    const form = document.getElementById('staffAssignSiteForm');
    const hidden = document.getElementById('selectedCategories');
    const writtenRequest = document.getElementById('written_request');
    let assignConfirmed = false;
    if (form) {
        form.addEventListener('submit', function (e) {
            if (langEl) {
                langEl.disabled = false;
            }
            syncLanguageHidden();
            if (!languageValue()) {
                e.preventDefault();
                if (window.slbAlert) {
                    window.slbAlert({ icon: 'warning', title: 'Select a language' });
                } else if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Select a language', timer: 2200, showConfirmButton: false });
                }
                return;
            }
            if (hidden && !String(hidden.value || '').trim()) {
                e.preventDefault();
                if (window.slbAlert) {
                    window.slbAlert({ icon: 'warning', title: 'Select at least one niche' });
                } else if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Select at least one niche', timer: 2200, showConfirmButton: false });
                }
                return;
            }
            if (imageInput && imageInput.files && imageInput.files[0]) {
                const maxKb = parseInt(imageInput.getAttribute('data-max-kb') || '10240', 10);
                const maxBytes = maxKb * 1024;
                if (imageInput.files[0].size > maxBytes) {
                    e.preventDefault();
                    const title = (window.SiteImageUpload && window.SiteImageUpload.sizeError)
                        ? window.SiteImageUpload.sizeError(maxKb)
                        : ('Site image must be under ' + Math.floor(maxKb / 1024) + ' MB');
                    if (window.slbAlert) {
                        window.slbAlert({ icon: 'warning', title: title });
                    } else if (window.Swal) {
                        Swal.fire({ icon: 'warning', title: title, timer: 2800, showConfirmButton: false });
                    }
                    return;
                }
            }
            if (writtenRequest && !writtenRequest.checked) {
                e.preventDefault();
                if (window.slbAlert) {
                    window.slbAlert({ icon: 'warning', title: 'Confirm you have a written request from this publisher’s account email' });
                } else if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Confirm you have a written request from this publisher’s account email', timer: 2800, showConfirmButton: false });
                }
                return;
            }
            if (!assignConfirmed && typeof window.slbConfirm === 'function') {
                e.preventDefault();
                window.slbConfirm({
                    title: 'Add site & notify publisher?',
                    text: 'This emails and bells the publisher. They must Accept the invite in My Sites.',
                    confirmText: 'Add site & notify',
                }).then(function (ok) {
                    if (!ok) return;
                    assignConfirmed = true;
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        HTMLFormElement.prototype.submit.call(form);
                    }
                });
            }
        });
    }
})();
</script>
@endsection
