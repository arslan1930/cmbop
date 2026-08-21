@extends(staff_layout())

@section('title', 'Edit site')

@section('content')
@php
    $isMarketingEditor = $isMarketingEditor ?? false;
    $marketingListingLocked = $marketingListingLocked ?? false;
    $categories = $categories ?? collect();
    $rawMarketingNiches = old('categories', $site->categories_array ?? []);
    if (! is_string($rawMarketingNiches) && ! is_iterable($rawMarketingNiches)) {
        $rawMarketingNiches = [];
    }
    if (is_string($rawMarketingNiches)) {
        $rawMarketingNiches = preg_split('/\|/', $rawMarketingNiches) ?: [];
    }
    $marketingNiches = \App\Models\Category::resolveNicheNames($rawMarketingNiches)['resolved'];
    // Never re-inject unresolved labels (e.g. group "Technology") into the form.
    if (is_string($marketingNiches)) {
        $marketingNiches = array_values(array_filter(array_map('trim', preg_split('/\|/', $marketingNiches) ?: [])));
    }
    $marketingNiches = collect($marketingNiches)
        ->flatten()
        ->filter(fn ($v) => is_scalar($v) && filled($v) && strtolower((string) $v) !== 'pending')
        ->map(fn ($v) => (string) $v)
        ->values()
        ->all();
    // After save, url()->previous() is this edit page — Back would look broken.
    $sitesBackUrl = staff_route('sites.index', array_filter([
        'publisher' => $site->publisher_id,
        'site' => $site->id,
    ]));
@endphp
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">{{ $isMarketingEditor ? ($marketingListingLocked ? ($site->marketingCanEditDescription() ? 'Edit description' : 'View site') : 'Fill metrics, geo & niches') : 'Edit site' }}</h4>
            <p class="text-muted mb-0 small">
                {{ $site->publisher?->name ?? 'Unknown publisher' }}
                @if($site->publisher?->email)
                    · {{ $site->publisher->email }}
                @endif
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ $sitesBackUrl }}" class="btn btn-sm btn-outline-secondary">← Back</a>
            <a href="{{ staff_route('sites.index') }}" class="btn btn-sm btn-outline-primary">Sites list</a>
        </div>
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

    @if($site->metrics_manual && ! $marketingListingLocked)
        <form id="allow-api-overwrite-form" method="POST" action="{{ staff_route('sites.allow-api-metrics', $site->id) }}">
            @csrf
        </form>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            @if($isMarketingEditor)
                @if($marketingListingLocked)
                    <div class="alert alert-warning border-0 mb-4">
                        @if($site->marketingCanEditDescription())
                            URL, price, and metrics stay locked on live listings. You can still update the advertiser-facing description.
                        @else
                            This listing is live, verified, or archived. Marketing cannot change it. Ask an admin.
                        @endif
                    </div>
                @else
                    <div class="alert alert-info border-0 mb-4">
                        Fix the URL, price, description, or metrics if needed.
                        Metrics, geo, and niche-only saves do not email the publisher.
                    </div>
                    @if(! $site->hasMarketplaceCountry())
                        <div class="alert alert-danger border-0 mb-3">
                            Missing marketplace country — marketing cannot activate until a country is set.
                        </div>
                    @endif
                    @if(! $site->hasGoodMetrics())
                        <div class="alert alert-warning border-0 mb-3">
                            Below the quality bar (DA ≥ {{ \App\Models\Site::GOOD_MIN_DA }}, DR ≥ {{ \App\Models\Site::GOOD_MIN_DR }}, traffic ≥ {{ number_format(\App\Models\Site::GOOD_MIN_TRAFFIC) }}). Update metrics before Activate.
                        </div>
                    @endif
                @endif

                <div class="row g-3 mb-4">
                    @if($marketingListingLocked)
                    <div class="col-md-4">
                        <div class="text-muted small">Website</div>
                        <div class="fw-semibold text-break">{{ $site->domain ?: $site->site_name }}</div>
                        <a class="small text-muted text-break" href="{{ $site->site_url }}" target="_blank" rel="noopener noreferrer">
                            {{ $site->site_url }}
                        </a>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Price</div>
                        <div class="fw-semibold">€{{ number_format((float) $site->price, 2) }}</div>
                    </div>
                    @endif
                    <div class="col-md-4">
                        @include('partials.staff-site-status-actions', [
                            'site' => $site,
                            'isMarketingEditor' => $isMarketingEditor,
                        ])
                    </div>
                </div>

                @if($marketingListingLocked)
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">DA</div>
                            <div class="fw-semibold">{{ $site->da }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">DR</div>
                            <div class="fw-semibold">{{ $site->dr }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Traffic</div>
                            <div class="fw-semibold">{{ number_format((int) $site->traffic) }}</div>
                        </div>
                        <div class="col-12" id="description">
                            @if($site->marketingCanEditDescription())
                                <form method="POST" action="{{ staff_route('sites.update', $site->id) }}">
                                    @csrf
                                    @method('PUT')
                                    @include('partials.site-description-editor', [
                                        'value' => old_text('description', $site->description),
                                        'required' => false,
                                        'editorId' => 'quillEditorLive',
                                        'help' => 'Advertisers see this on the listing. Live URL, price, and metrics stay locked.',
                                    ])
                                    <button type="submit" class="btn btn-primary mt-2">
                                        <i class="fa fa-save me-1"></i> Save description
                                    </button>
                                </form>
                            @else
                                <div class="text-muted small">Description</div>
                                <div class="site-description-readonly border rounded p-3 bg-white mt-1">
                                    @if($site->safeDescriptionHtml() !== '')
                                        {!! $site->safeDescriptionHtml() !!}
                                    @else
                                        <span class="text-muted">No description yet</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                <form method="POST" action="{{ staff_route('sites.update', $site->id) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_name">Site name <span class="text-danger">*</span></label>
                            <input type="text" id="site_name" name="site_name" class="form-control @error('site_name') is-invalid @enderror"
                                   value="{{ old_text('site_name', $site->site_name) }}" required>
                            @error('site_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_url">Site URL <span class="text-danger">*</span></label>
                            <input type="text" id="site_url" name="site_url" class="form-control @error('site_url') is-invalid @enderror" placeholder="https://example.com"
                                   value="{{ old_text('site_url', $site->site_url) }}" required>
                            @error('site_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="example_url">Example URL</label>
                            <input type="text" id="example_url" name="example_url" class="form-control @error('example_url') is-invalid @enderror" placeholder="https://example.com/sample-post"
                                   value="{{ old_text('example_url', $site->example_url) }}">
                            <div class="form-text">Optional. If set, must be on the same domain as the site URL.</div>
                            @error('example_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="price">Price (€) <span class="text-danger">*</span></label>
                            <input type="number" id="price" name="price" class="form-control @error('price') is-invalid @enderror"
                                   min="0" step="0.01" required
                                   value="{{ old_text('price', $site->price) }}">
                            @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="country">Country <span class="text-danger">*</span></label>
                            <select id="country" name="country" class="form-select @error('country') is-invalid @enderror" required>
                                <option value="">Select…</option>
                                @foreach($countries as $country)
                                    <option value="{{ strtolower($country->code) }}"
                                        @selected(old_text('country', strtolower((string) $site->country)) === strtolower($country->code))>
                                        {{ $country->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Pick country first.</div>
                            @error('country')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="language">Language <span class="text-danger">*</span></label>
                            <select id="language" name="language" class="form-select @error('language') is-invalid @enderror" required>
                                <option value="">Select country first</option>
                                @foreach($languages as $language)
                                    <option value="{{ strtolower($language->code) }}"
                                        @selected(old_text('language', strtolower((string) $site->language)) === strtolower($language->code))>
                                        {{ $language->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Only languages paired with that country.</div>
                            @error('language')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="da">DA <span class="text-danger">*</span></label>
                            <input type="number" id="da" name="da" class="form-control @error('da') is-invalid @enderror"
                                   min="0" max="100" step="1" required
                                   value="{{ old_text('da', $site->da) }}">
                            @error('da')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="dr">DR <span class="text-danger">*</span></label>
                            <input type="number" id="dr" name="dr" class="form-control @error('dr') is-invalid @enderror"
                                   min="0" max="100" step="1" required
                                   value="{{ old_text('dr', $site->dr) }}">
                            @error('dr')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="traffic">Traffic <span class="text-danger">*</span></label>
                            <input type="number" id="traffic" name="traffic" class="form-control @error('traffic') is-invalid @enderror"
                                   min="0" max="4294967295" step="1" inputmode="numeric" required
                                   placeholder="e.g. 1500000"
                                   value="{{ old_text('traffic', $site->traffic) }}">
                            @error('traffic')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        @if($site->metrics_manual)
                        <div class="col-12">
                            <div class="alert alert-warning border-0 py-2 mb-0 d-flex flex-wrap align-items-center gap-2">
                                <span>Manual lock skips API providers.</span>
                                <button type="submit" form="allow-api-overwrite-form" class="btn btn-sm btn-outline-dark">Allow API overwrite</button>
                            </div>
                        </div>
                        @endif
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="categoryInput">Niches <span class="text-danger">*</span> (max 7)</label>
                            <input type="hidden"
                                   name="categories"
                                   id="selectedCategories"
                                   value="{{ implode('|', $marketingNiches) }}">
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
                            <div class="form-text">Same niches as Catalog. Type and press Enter to add; Backspace removes the last chip. Max 7.</div>
                            @error('categories')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold" for="site_image">Site image</label>
                            <input type="file" id="site_image" name="site_image"
                                   class="form-control @error('site_image') is-invalid @enderror"
                                   accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                                   data-max-kb="{{ \App\Support\SiteImageUpload::maxKilobytes() }}"
                                   data-php-max-kb="{{ \App\Support\SiteImageUpload::phpUploadMaxKilobytes() }}">
                            <div class="form-text">Optional desktop screenshot (JPEG, PNG, GIF, or WebP up to {{ \App\Support\SiteImageUpload::maxMegabytesLabel() }}&nbsp;MB). Hover the preview to zoom. Leave empty to keep the current image.</div>
                            @error('site_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div id="siteImagePreview"
                                 class="site-image-desktop-preview {{ $site->site_image ? '' : 'is-empty' }}"
                                 data-existing="{{ $site->site_image ? rtrim(staff_base_path(), '/').'/sites/media/'.$site->site_image : '' }}"
                                 data-existing-fallback="{{ $site->site_image ? '/storage/'.$site->site_image : '' }}">
                                @if($site->site_image)
                                    <img src="{{ rtrim(staff_base_path(), '/').'/sites/media/'.$site->site_image }}"
                                         data-media-fallback="{{ '/storage/'.$site->site_image }}"
                                         alt="Current site image"
                                         onerror="if(!this.dataset.triedMedia&&this.dataset.mediaFallback){this.dataset.triedMedia='1';this.src=this.dataset.mediaFallback;}else{this.parentElement.classList.add('is-empty');this.remove();}">
                                @else
                                    <span>No image yet — choose a desktop-size screenshot (16:10)</span>
                                @endif
                            </div>
                        </div>

                        <div class="col-12">
                            @include('partials.site-description-editor', [
                                'value' => old_text('description', $site->description),
                                'required' => true,
                            ])
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save me-1"></i> Save listing &amp; metrics
                        </button>
                        <a href="{{ $sitesBackUrl }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>

                <link href="{{ asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
                <script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
                <script src="{{ asset('js/multi-select.js') }}?v={{ @filemtime(public_path('js/multi-select.js')) ?: '1' }}"></script>
                <script>
                (function () {
                    const prefills = @json($marketingNiches);
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
                    const form = document.querySelector('form[action*="sites"]');
                    const hidden = document.getElementById('selectedCategories');
                    const imageInput = document.getElementById('site_image');
                    if (form && hidden) {
                        form.addEventListener('submit', function (e) {
                            if (!String(hidden.value || '').trim()) {
                                e.preventDefault();
                                if (window.Swal) {
                                    Swal.fire({ icon: 'warning', title: 'Select at least one niche', timer: 2200, showConfirmButton: false });
                                } else {
                                    slbAlert({ icon: 'warning', title: 'Select at least one niche' });
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
                                    if (window.Swal) {
                                        Swal.fire({ icon: 'warning', title: title, timer: 2800, showConfirmButton: false });
                                    } else {
                                        slbAlert({ icon: 'warning', title: title });
                                    }
                                }
                            }
                        });
                    }
                })();
                </script>
                @endif
            @else
                <form method="POST" action="{{ staff_route('sites.update', $site->id) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_name">Site name</label>
                            <input type="text" id="site_name" name="site_name" class="form-control"
                                   value="{{ old_text('site_name', $site->site_name) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_url">Site URL</label>
                            <input type="text" id="site_url" name="site_url" class="form-control" placeholder="https://example.com"
                                   value="{{ old_text('site_url', $site->site_url) }}" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="da">DA</label>
                            <input type="number" id="da" name="da" class="form-control" min="0" max="100"
                                   value="{{ old_text('da', $site->da) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="dr">DR</label>
                            <input type="number" id="dr" name="dr" class="form-control" min="0" max="100"
                                   value="{{ old_text('dr', $site->dr) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="traffic">Traffic</label>
                            <input type="number" id="traffic" name="traffic" class="form-control" min="0" max="4294967295"
                                   step="1" inputmode="numeric" placeholder="e.g. 1500000"
                                   value="{{ old_text('traffic', $site->traffic) }}">
                        </div>
                        @if($site->metrics_manual)
                        <div class="col-12">
                            <div class="alert alert-warning border-0 py-2 mb-0 d-flex flex-wrap align-items-center gap-2">
                                <span>Manual lock skips API providers.</span>
                                <button type="submit" form="allow-api-overwrite-form" class="btn btn-sm btn-outline-dark">Allow API overwrite</button>
                            </div>
                        </div>
                        @endif

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="price">Price (€)</label>
                            <input type="number" id="price" name="price" class="form-control" min="0" step="0.01"
                                   value="{{ old_text('price', $site->price) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="country">Country</label>
                            <select id="country" name="country" class="form-select">
                                <option value="">Select…</option>
                                @foreach($countries as $country)
                                    <option value="{{ strtolower($country->code) }}"
                                        @selected(old_text('country', strtolower((string) $site->country)) === strtolower($country->code))>
                                        {{ $country->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="language">Language</label>
                            <select id="language" name="language" class="form-select">
                                <option value="">Select country first</option>
                                @foreach($languages as $language)
                                    <option value="{{ strtolower($language->code) }}"
                                        @selected(old_text('language', strtolower((string) $site->language)) === strtolower($language->code))>
                                        {{ $language->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="category">Category</label>
                            <input type="text" id="category" name="category" class="form-control"
                                   value="{{ old_text('category', $site->category) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="example_url">Example URL</label>
                            <input type="text" id="example_url" name="example_url" class="form-control" placeholder="https://example.com/sample-post"
                                   value="{{ old_text('example_url', $site->example_url) }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="publication_time">Publication time</label>
                            <input type="text" id="publication_time" name="publication_time" class="form-control"
                                   value="{{ old_text('publication_time', $site->publication_time) }}" placeholder="permanent">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="link_type">Link type</label>
                            <input type="text" id="link_type" name="link_type" class="form-control"
                                   value="{{ old_text('link_type', $site->link_type) }}" placeholder="dofollow">
                        </div>

                            <div class="col-12">
                            @include('partials.site-description-editor', [
                                'value' => old_text('description', $site->description),
                                'required' => false,
                            ])
                        </div>

                        @php
                            $homepageDays = config('site_placement.homepage_days', [1, 7, 30]);
                            $existingHomepage = is_array($site->homepage_placement_prices) ? $site->homepage_placement_prices : [];
                            $existingSocial = is_array($site->social_promotion) ? $site->social_promotion : [];
                        @endphp
                        <div class="col-12">
                            <input type="hidden" name="placement_offers_form" value="1">
                            <div class="border rounded p-3 bg-light">
                                <p class="fw-semibold mb-1">Homepage &amp; social promotions (optional)</p>
                                <p class="small text-muted mb-3">Advertisers see these in catalog Site Details. Leave unchecked to hide the offer.</p>
                                <p class="fw-semibold small mb-2">Homepage placement</p>
                                <div class="d-flex flex-wrap gap-3 mb-3">
                                    @foreach($homepageDays as $days)
                                        @php
                                            $checked = old("homepage.$days", array_key_exists((string) $days, $existingHomepage) || array_key_exists($days, $existingHomepage));
                                            $priceVal = old_text("price_homepage.$days", $existingHomepage[(string) $days] ?? $existingHomepage[$days] ?? '');
                                        @endphp
                                        <div style="min-width:140px;">
                                            <div class="form-check">
                                                <input type="checkbox" name="homepage[{{ $days }}]" value="1"
                                                       class="form-check-input" id="adminHomepage{{ $days }}"
                                                       {{ $checked ? 'checked' : '' }}>
                                                <label class="form-check-label" for="adminHomepage{{ $days }}">{{ $days }} day{{ $days > 1 ? 's' : '' }}</label>
                                            </div>
                                            <input type="number" name="price_homepage[{{ $days }}]" class="form-control mt-1"
                                                   placeholder="Fee (€) — 0 = Free" min="0" step="0.01" inputmode="decimal"
                                                   value="{{ $priceVal }}">
                                        </div>
                                    @endforeach
                                </div>
                                <p class="fw-semibold small mb-2">Social media sharing (always free)</p>
                                <div class="d-flex flex-wrap gap-3">
                                    @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X'] as $channel => $label)
                                        <div class="form-check">
                                            <input type="checkbox" name="social[{{ $channel }}]" value="1"
                                                   class="form-check-input" id="adminSocial{{ ucfirst($channel) }}"
                                                   {{ old("social.$channel", !empty($existingSocial[$channel])) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="adminSocial{{ ucfirst($channel) }}">{{ $label }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold" for="site_image">Site image</label>
                            <input type="file" id="site_image" name="site_image"
                                   class="form-control @error('site_image') is-invalid @enderror"
                                   accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                                   data-max-kb="{{ \App\Support\SiteImageUpload::maxKilobytes() }}"
                                   data-php-max-kb="{{ \App\Support\SiteImageUpload::phpUploadMaxKilobytes() }}">
                            <div class="form-text">Desktop screenshot (JPEG, PNG, GIF, or WebP up to {{ \App\Support\SiteImageUpload::maxMegabytesLabel() }}&nbsp;MB). Hover the preview to zoom. Leave empty to keep the current image.</div>
                            @error('site_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div id="siteImagePreview"
                                 class="site-image-desktop-preview {{ $site->site_image ? '' : 'is-empty' }}"
                                 data-existing="{{ $site->site_image ? rtrim(staff_base_path(), '/').'/sites/media/'.$site->site_image : '' }}"
                                 data-existing-fallback="{{ $site->site_image ? '/storage/'.$site->site_image : '' }}">
                                @if($site->site_image)
                                    <img src="{{ rtrim(staff_base_path(), '/').'/sites/media/'.$site->site_image }}"
                                         data-media-fallback="{{ '/storage/'.$site->site_image }}"
                                         alt="Current site image"
                                         onerror="if(!this.dataset.triedMedia&&this.dataset.mediaFallback){this.dataset.triedMedia='1';this.src=this.dataset.mediaFallback;}else{this.parentElement.classList.add('is-empty');this.remove();}">
                                @else
                                    <span>No image yet — choose a desktop-size screenshot (16:10)</span>
                                @endif
                            </div>
                        </div>

                        <div class="col-md-6">
                            @include('partials.staff-site-status-actions', [
                                'site' => $site,
                                'isMarketingEditor' => false,
                            ])
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save me-1"></i> Save changes
                        </button>
                        <a href="{{ $sitesBackUrl }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            @endif
        </div>
    </div>

</div>

<script>
(function () {
    const map = @json($countryLanguageMap ?? new \stdClass());
    const countryEl = document.getElementById('country');
    const langEl = document.getElementById('language');
    const preferredLang = @json(old_text('language', strtolower((string) ($site->language ?? ''))));

    function refreshLanguages() {
        if (!countryEl || !langEl) return;
        const code = (countryEl.value || '').toLowerCase();
        const list = map[code] || [];
        const keep = (langEl.value || preferredLang || '').toLowerCase();
        langEl.innerHTML = '';
        if (!code) {
            langEl.disabled = true;
            langEl.innerHTML = '<option value="">Select country first</option>';
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
        if (list.length === 1 && !langEl.value) {
            langEl.value = list[0].code;
        }
    }

    if (countryEl) {
        countryEl.addEventListener('change', refreshLanguages);
        refreshLanguages();
    }
})();
</script>
<script src="{{ asset('assets/js/site-image-upload.js') }}?v={{ @filemtime(public_path('assets/js/site-image-upload.js')) ?: '1' }}"></script>
<script>
(function () {
    const imageInput = document.getElementById('site_image');
    const preview = document.getElementById('siteImagePreview');
    if (!imageInput || !preview) return;

    const existingSrc = preview.getAttribute('data-existing') || '';
    const existingFallback = preview.getAttribute('data-existing-fallback') || '';

    function bindMediaFallback(img) {
        if (!img || !existingFallback) return;
        img.setAttribute('data-media-fallback', existingFallback);
        img.onerror = function () {
            if (!this.dataset.triedMedia && this.dataset.mediaFallback) {
                this.dataset.triedMedia = '1';
                this.src = this.dataset.mediaFallback;
                return;
            }
            preview.classList.add('is-empty');
            this.remove();
        };
    }

    function showExistingOrEmpty() {
        if (existingSrc) {
            preview.classList.remove('is-empty');
            preview.innerHTML = '<img src="' + existingSrc + '" alt="Current site image">';
            bindMediaFallback(preview.querySelector('img'));
        } else {
            preview.classList.add('is-empty');
            preview.innerHTML = '<span>No image yet — choose a desktop-size screenshot (16:10)</span>';
        }
    }

    function warnSize(title) {
        if (window.Swal) {
            Swal.fire({ icon: 'warning', title: title, timer: 2800, showConfirmButton: false });
        } else if (window.slbAlert) {
            slbAlert({ icon: 'warning', title: title });
        }
    }

    bindMediaFallback(preview.querySelector('img'));
    if (window.SiteImageUpload) {
        window.SiteImageUpload.bindHoverZoom(preview);
        window.SiteImageUpload.bindSiteImageInput({
            input: imageInput,
            preview: preview,
            existingSrc: existingSrc,
            onError: warnSize,
            onReady: function (file) {
                if (!file) {
                    bindMediaFallback(preview.querySelector('img'));
                }
            },
        });
        return;
    }

    imageInput.addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) {
            showExistingOrEmpty();
            return;
        }
        const maxKb = parseInt(imageInput.getAttribute('data-max-kb') || '10240', 10);
        if (file.size > maxKb * 1024) {
            this.value = '';
            showExistingOrEmpty();
            warnSize('Site image must be under ' + Math.floor(maxKb / 1024) + ' MB');
            return;
        }
        const reader = new FileReader();
        reader.onload = function (e) {
            preview.classList.remove('is-empty');
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Selected site image">';
        };
        reader.readAsDataURL(file);
    });
})();
</script>
@endsection
