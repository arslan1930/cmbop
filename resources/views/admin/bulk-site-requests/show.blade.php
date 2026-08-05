@extends(staff_layout())

@section('content')
<div class="container-fluid">
    <div class="mb-3">
        <a href="{{ staff_route('bulk-site-requests.index') }}" class="small text-muted text-decoration-none">
            ← Bulk requests
        </a>
        <h3 class="mt-2 mb-1">Bulk request #{{ $bulkRequest->id }}</h3>
        <p class="text-muted small mb-0">
            Publisher: <strong>{{ $bulkRequest->publisher->name }}</strong>
            ({{ $bulkRequest->publisher->email }})
            · Status: <strong>{{ $bulkRequest->statusLabel() }}</strong>
            · Sites submitted: {{ $bulkRequest->items->count() ?: ($bulkRequest->estimated_count ?? '—') }}
            · Pending to add: {{ $pendingItems->count() }}
        </p>
    </div>

    @if(session('seed_failures'))
        <div class="alert alert-warning">
            <strong>Some rows failed</strong>
            <ul class="mb-0 small mt-2">
                @foreach(session('seed_failures') as $fail)
                    <li>Line {{ $fail['line'] }} · {{ $fail['url'] ?? '' }} — {{ implode('; ', $fail['errors'] ?? []) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold">Publisher note</h6>
                    <p class="small mb-0">{{ $bulkRequest->publisher_note ?: '—' }}</p>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">Ops actions</h6>
                    <form method="POST" action="{{ staff_route('bulk-site-requests.notes', $bulkRequest) }}" class="mb-3">
                        @csrf
                        <label class="form-label small">Internal notes</label>
                        <textarea name="admin_notes" class="form-control form-control-sm mb-2" rows="3">{{ old_text('admin_notes', $bulkRequest->admin_notes) }}</textarea>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Save notes</button>
                    </form>

                    @if($bulkRequest->isOpen())
                        <form method="POST" action="{{ staff_route('bulk-site-requests.sheet-sent', $bulkRequest) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                Mark sheet emailed (optional)
                            </button>
                        </form>
                        <form method="POST" action="{{ staff_route('bulk-site-requests.cancel', $bulkRequest) }}"
                              data-slb-confirm="Cancel this bulk request? History is kept."
                              data-slb-confirm-title="Cancel bulk request?"
                              data-slb-confirm-text="Cancel request"
                              data-slb-confirm-danger="1">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">Cancel request</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">History</h6>
                    <p class="small text-muted mb-3">Append-only audit trail. Cannot be deleted.</p>
                    <div class="bulk-history-list" style="max-height: 28rem; overflow-y: auto;">
                        @forelse($history as $entry)
                            <div class="border-bottom py-2 small">
                                <div class="fw-semibold">{{ marketing_task_label($entry->action) }}</div>
                                <div class="text-muted">{{ $entry->description }}</div>
                                <div class="text-muted mt-1" style="font-size:.72rem;">
                                    {{ $entry->user_name ?? 'System' }}
                                    @if($entry->role) · {{ $entry->role }} @endif
                                    · {{ $entry->created_at?->timezone(config('app.timezone'))->format('M j, Y H:i') }}
                                </div>
                            </div>
                        @empty
                            <p class="small text-muted mb-0">No history yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Publisher submitted (URL + price only)</h6>
                    <p class="small text-muted mb-3">
                        Review each website, then fill <strong>Language, Country, DA, DR, Traffic, and Niches</strong> per row before Done.
                        Sites are added to the publisher’s Pending sites as drafts — still inactive until they finish details and you verify.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Website URL</th>
                                    <th>Price</th>
                                    <th>Domain</th>
                                    <th>Added?</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($bulkRequest->items as $item)
                                    <tr>
                                        <td>
                                            <a href="{{ $item->site_url }}" target="_blank" rel="noopener noreferrer">
                                                {{ $item->site_url }}
                                            </a>
                                        </td>
                                        <td>€{{ number_format((float) $item->price, 2) }}</td>
                                        <td class="small text-muted">{{ $item->domain }}</td>
                                        <td>
                                            @if($item->site_id)
                                                <span class="badge text-bg-success">Yes</span>
                                            @else
                                                <span class="badge text-bg-light border">Pending</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-muted text-center py-3">
                                            No URL + price rows (legacy request before in-app submission).
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3 border-primary-subtle">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Done — add sites &amp; notify publisher</h6>
                    <p class="small text-muted mb-3">
                        <strong>{{ $pendingItems->count() }}</strong> website(s) still pending
                        (publisher + marketer share a {{ \App\Models\BulkSiteRequest::MAX_SITES_PER_REQUEST }}-site batch limit).
                        Fill a complete block (Language, Country, DA, DR, Traffic, Niches) and click Done — one row, several, or all at once.
                        Finished rows become drafts and notify the publisher; the rest stay here until you fill them.
                    </p>

                    @if($errors->any())
                        <div class="alert alert-danger py-2 small">
                            <strong>Finish the boxes first.</strong>
                            {{ $errors->first() }}
                        </div>
                    @endif

                    @if($pendingItems->isEmpty())
                        <div class="form-text">All submitted rows are already added.</div>
                    @else
                        <form method="POST"
                              action="{{ staff_route('bulk-site-requests.done', $bulkRequest) }}"
                              id="bulkDoneForm"
                              novalidate>
                            @csrf
                            <div class="bulk-done-table-wrap admin-contained-scroll mb-3">
                                <table class="table table-sm align-middle mb-0 bulk-done-grid">
                                    <thead>
                                        <tr>
                                            <th>Website</th>
                                            <th>Price</th>
                                            <th>Language <span class="text-danger">*</span></th>
                                            <th>Country <span class="text-danger">*</span></th>
                                            <th>DA <span class="text-danger">*</span></th>
                                            <th>DR <span class="text-danger">*</span></th>
                                            <th>Traffic <span class="text-danger">*</span></th>
                                            <th>Niches <span class="text-danger">*</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($pendingItems as $item)
                                            @php
                                                $old = old('items.'.$item->id, []);
                                                $oldCategories = $old['categories'] ?? '';
                                                if (is_array($oldCategories)) {
                                                    $oldCategories = implode('|', $oldCategories);
                                                }
                                                $uid = 'done'.$item->id;
                                            @endphp
                                            <tr data-bulk-done-row>
                                                <td>
                                                    <div class="fw-semibold small text-break">{{ $item->domain }}</div>
                                                    <a class="small text-muted text-break" href="{{ $item->site_url }}" target="_blank" rel="noopener noreferrer">
                                                        {{ $item->site_url }}
                                                    </a>
                                                </td>
                                                <td class="text-nowrap">€{{ number_format((float) $item->price, 2) }}</td>
                                                <td>
                                                    <select name="items[{{ $item->id }}][language]"
                                                            class="form-select form-select-sm @error('items.'.$item->id.'.language') is-invalid @enderror"
                                                            required
                                                            data-bulk-required>
                                                        <option value="">Select…</option>
                                                        @foreach($languages as $language)
                                                            <option value="{{ strtolower($language->code) }}"
                                                                @selected(($old['language'] ?? '') === strtolower($language->code))>
                                                                {{ $language->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    @error('items.'.$item->id.'.language')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </td>
                                                <td>
                                                    <select name="items[{{ $item->id }}][country]"
                                                            class="form-select form-select-sm @error('items.'.$item->id.'.country') is-invalid @enderror"
                                                            required
                                                            data-bulk-required>
                                                        <option value="">Select…</option>
                                                        @foreach($countries as $country)
                                                            <option value="{{ strtolower($country->code) }}"
                                                                @selected(($old['country'] ?? '') === strtolower($country->code))>
                                                                {{ $country->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    @error('items.'.$item->id.'.country')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </td>
                                                <td>
                                                    <input type="number"
                                                           name="items[{{ $item->id }}][da]"
                                                           class="form-control form-control-sm @error('items.'.$item->id.'.da') is-invalid @enderror"
                                                           placeholder="0–100"
                                                           min="0" max="100" step="1"
                                                           value="{{ $old['da'] ?? '' }}"
                                                           required
                                                           data-bulk-required
                                                           data-score-clamp="100">
                                                    @error('items.'.$item->id.'.da')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </td>
                                                <td>
                                                    <input type="number"
                                                           name="items[{{ $item->id }}][dr]"
                                                           class="form-control form-control-sm @error('items.'.$item->id.'.dr') is-invalid @enderror"
                                                           placeholder="0–100"
                                                           min="0" max="100" step="1"
                                                           value="{{ $old['dr'] ?? '' }}"
                                                           required
                                                           data-bulk-required
                                                           data-score-clamp="100">
                                                    @error('items.'.$item->id.'.dr')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </td>
                                                <td>
                                                    {{-- Traffic is monthly visitors (can be millions/billions). Never clamp like DA/DR. --}}
                                                    <input type="number"
                                                           name="items[{{ $item->id }}][traffic]"
                                                           class="form-control form-control-sm @error('items.'.$item->id.'.traffic') is-invalid @enderror"
                                                           placeholder="e.g. 1500000"
                                                           min="0"
                                                           max="4294967295"
                                                           step="1"
                                                           inputmode="numeric"
                                                           value="{{ $old['traffic'] ?? '' }}"
                                                           required
                                                           data-bulk-required
                                                           data-traffic-input>
                                                    @error('items.'.$item->id.'.traffic')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </td>
                                                <td class="bulk-done-niches-cell">
                                                    <input type="hidden"
                                                           name="items[{{ $item->id }}][categories]"
                                                           id="selectedCategories-{{ $uid }}"
                                                           value="{{ $oldCategories }}"
                                                           data-bulk-required
                                                           class="@error('items.'.$item->id.'.categories') is-invalid @enderror">
                                                    <div class="multi-select-wrapper" id="categoryWrapper-{{ $uid }}">
                                                        <div class="multi-select-input multi-select-input--sm"
                                                             id="categoryInput-{{ $uid }}"
                                                             role="button"
                                                             tabindex="0"
                                                             aria-haspopup="listbox"
                                                             aria-label="Select niches for {{ $item->domain }}">
                                                            <span class="multi-select-placeholder">Select niches…</span>
                                                        </div>
                                                        <div class="multi-select-dropdown" id="categoryDropdown-{{ $uid }}" role="listbox">
                                                            <div class="multi-select-search">
                                                                <input type="text" placeholder="Search niches…" id="categorySearch-{{ $uid }}" autocomplete="off">
                                                            </div>
                                                            <div class="multi-select-options" id="categoryOptions-{{ $uid }}">
                                                                @foreach($categories as $category)
                                                                    <div class="multi-select-option"
                                                                         data-value="{{ $category->name }}"
                                                                         data-label="{{ $category->name }}">{{ $category->name }}</div>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    </div>
                                                    @error('items.'.$item->id.'.categories')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div id="bulkDoneHint" class="alert alert-warning py-2 small mb-3" role="status">
                                Fill at least one complete block (Language, Country, DA, DR, Traffic, Niches) before Done.
                            </div>

                            <button type="submit"
                                    id="bulkDoneSubmit"
                                    class="btn btn-primary"
                                    data-open="{{ $bulkRequest->isOpen() ? '1' : '0' }}"
                                    disabled>
                                Done — add filled sites &amp; notify publisher
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Advanced: seed with per-row metrics</h6>
                    <p class="small text-muted mb-3">
                        Optional. Paste custom rows when metrics differ per site.
                        Columns: <code>url,price,da,dr,traffic,language,country[,site_name]</code>
                    </p>
                    @php
                        $seedStarter = $pendingItems->map(function ($item) {
                            return $item->site_url.','.$item->price.',0,0,0,lang,country';
                        })->implode("\n");
                    @endphp
                    @if($seedStarter !== '')
                        <div class="small mb-2">
                            <span class="text-muted">Starter from pending URL + price (replace lang/country and metrics):</span>
                            <pre class="bg-light border rounded p-2 small mb-2 mt-1" id="bulkSeedStarter" style="max-height:8rem;overflow:auto;">{{ $seedStarter }}</pre>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkCopySeedStarter">Copy starter into box</button>
                        </div>
                    @endif
                    <form method="POST" action="{{ staff_route('bulk-site-requests.seed', $bulkRequest) }}">
                        @csrf
                        <textarea name="rows" id="bulkSeedRows" class="form-control font-monospace small @error('rows') is-invalid @enderror" rows="8"
                                  placeholder="https://example.com,99,40,45,12000,de,de,Example Blog">{{ old_text('rows', $seedStarter) }}</textarea>
                        @error('rows')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-outline-primary btn-sm mt-2" @disabled(! $bulkRequest->isOpen())>
                            Seed from pasted rows &amp; notify publisher
                        </button>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">Sites on publisher panel ({{ $bulkRequest->sites->count() }})</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Site</th>
                                    <th>Price</th>
                                    <th>DR/DA</th>
                                    <th>Lang/Country</th>
                                    <th>Onboarding</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($bulkRequest->sites as $site)
                                    <tr id="bulk-site-row-{{ $site->id }}">
                                        <td>
                                            <div class="fw-semibold">{{ $site->site_name }}</div>
                                            <div class="small text-muted">{{ $site->domain }}</div>
                                        </td>
                                        <td>€{{ number_format((float) $site->price, 2) }}</td>
                                        <td>{{ $site->dr }} / {{ $site->da }}</td>
                                        <td class="text-uppercase small">{{ $site->language }} / {{ $site->country }}</td>
                                        <td>
                                            <span class="badge text-bg-light border text-capitalize">
                                                {{ str_replace('_', ' ', $site->onboarding_status ?? '—') }}
                                            </span>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a href="{{ staff_route('sites.edit', $site->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                            @if($canDeleteDrafts && (auth()->user()->isAdmin() || $site->canBeDeletedByMarketing()))
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-danger bulk-draft-delete"
                                                        data-site-id="{{ $site->id }}"
                                                        data-site-name="{{ $site->site_name }}">
                                                    Delete
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-muted text-center py-3">No sites added yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<link href="{{ asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
<script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="{{ asset('js/multi-select.js') }}?v={{ @filemtime(public_path('js/multi-select.js')) ?: '1' }}"></script>
<script>
document.getElementById('bulkCopySeedStarter')?.addEventListener('click', function () {
    const starter = document.getElementById('bulkSeedStarter');
    const box = document.getElementById('bulkSeedRows');
    if (!starter || !box) return;
    box.value = starter.textContent.trim();
    box.focus();
});

(function () {
    const form = document.getElementById('bulkDoneForm');
    if (!form) return;

    const submitBtn = document.getElementById('bulkDoneSubmit');
    const hint = document.getElementById('bulkDoneHint');
    const fields = () => Array.from(form.querySelectorAll('[data-bulk-required]'));
    const multiSelects = {};
    const prefills = {};
    const hasServerOld = @json((bool) old('items'));
    const draftKey = @json('bulkDoneDraft:'.$bulkRequest->id.':'.auth()->id());
    const draftTtlMs = 24 * 60 * 60 * 1000;

    @foreach($pendingItems as $item)
        @php
            $oldCats = old('items.'.$item->id.'.categories', '');
            if (is_array($oldCats)) {
                $oldCats = implode('|', $oldCats);
            }
            $oldCatsList = array_values(array_filter(array_map('trim', preg_split('/\|/', (string) $oldCats) ?: [])));
        @endphp
        prefills[{{ (int) $item->id }}] = @json($oldCatsList);
    @endforeach

    Object.keys(prefills).forEach(function (itemId) {
        const uid = 'done' + itemId;
        const ms = window.initMultiSelect({
            wrapperId: 'categoryWrapper-' + uid,
            inputId: 'categoryInput-' + uid,
            dropdownId: 'categoryDropdown-' + uid,
            optionsId: 'categoryOptions-' + uid,
            hiddenInputId: 'selectedCategories-' + uid,
            searchId: 'categorySearch-' + uid,
            maxSelections: 7,
            placeholderText: 'Select niches…',
        });
        if (!ms) return;
        multiSelects[itemId] = ms;
        const values = prefills[itemId] || [];
        if (values.length) {
            ms.setSelectedItems(values, values);
        }
    });

    function readDraft() {
        try {
            const raw = sessionStorage.getItem(draftKey);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object' || !parsed.items) return null;
            if (!parsed.savedAt || (Date.now() - Number(parsed.savedAt)) > draftTtlMs) {
                sessionStorage.removeItem(draftKey);
                return null;
            }
            return parsed;
        } catch (e) {
            return null;
        }
    }

    function writeDraft() {
        const items = {};
        form.querySelectorAll('[data-bulk-done-row]').forEach(function (row) {
            const language = row.querySelector('select[name*="[language]"]');
            const country = row.querySelector('select[name*="[country]"]');
            const da = row.querySelector('input[name*="[da]"]');
            const dr = row.querySelector('input[name*="[dr]"]');
            const traffic = row.querySelector('input[name*="[traffic]"]');
            const categories = row.querySelector('input[name*="[categories]"]');
            const name = (language && language.name) || '';
            const match = name.match(/items\[(\d+)\]/);
            if (!match) return;
            const itemId = match[1];
            items[itemId] = {
                language: language ? language.value : '',
                country: country ? country.value : '',
                da: da ? da.value : '',
                dr: dr ? dr.value : '',
                traffic: traffic ? traffic.value : '',
                categories: categories ? categories.value : '',
            };
        });
        try {
            sessionStorage.setItem(draftKey, JSON.stringify({
                savedAt: Date.now(),
                items: items,
            }));
        } catch (e) {}
    }

    function clearDraft() {
        try { sessionStorage.removeItem(draftKey); } catch (e) {}
    }

    function restoreDraftIfNeeded() {
        if (hasServerOld) return;
        const draft = readDraft();
        if (!draft || !draft.items) return;

        Object.keys(draft.items).forEach(function (itemId) {
            const data = draft.items[itemId] || {};
            const language = form.querySelector('select[name="items[' + itemId + '][language]"]');
            const country = form.querySelector('select[name="items[' + itemId + '][country]"]');
            const da = form.querySelector('input[name="items[' + itemId + '][da]"]');
            const dr = form.querySelector('input[name="items[' + itemId + '][dr]"]');
            const traffic = form.querySelector('input[name="items[' + itemId + '][traffic]"]');
            if (language && data.language) language.value = data.language;
            if (country && data.country) country.value = data.country;
            if (da && data.da !== undefined && data.da !== null) da.value = data.da;
            if (dr && data.dr !== undefined && data.dr !== null) dr.value = data.dr;
            if (traffic && data.traffic !== undefined && data.traffic !== null) traffic.value = data.traffic;

            const nicheValues = String(data.categories || '')
                .split('|')
                .map(function (v) { return v.trim(); })
                .filter(Boolean);
            const categoriesInput = form.querySelector('input[name="items[' + itemId + '][categories]"]');
            if (nicheValues.length && multiSelects[itemId]) {
                multiSelects[itemId].setSelectedItems(nicheValues, nicheValues);
            } else if (categoriesInput && data.categories !== undefined && data.categories !== null) {
                // Keep hidden field in sync even if multi-select init failed.
                categoriesInput.value = String(data.categories || '');
            }
        });
    }

    function fieldFilled(el) {
        const value = String(el.value ?? '').trim();
        if (value === '') return false;
        if (el.type === 'number') {
            const n = Number(value);
            if (Number.isNaN(n)) return false;
            if (el.min !== '' && n < Number(el.min)) return false;
            if (el.max !== '' && n > Number(el.max)) return false;
        }
        return true;
    }

    function rowFields(row) {
        return Array.from(row.querySelectorAll('[data-bulk-required]'));
    }

    function rowFilled(row) {
        return rowFields(row).every(fieldFilled);
    }

    function rowStarted(row) {
        return rowFields(row).some(fieldFilled);
    }

    function doneRows() {
        return Array.from(form.querySelectorAll('[data-bulk-done-row]'));
    }

    function completeRows() {
        return doneRows().filter(rowFilled);
    }

    function partialRows() {
        return doneRows().filter(function (row) {
            return rowStarted(row) && !rowFilled(row);
        });
    }

    function rowItemId(row) {
        const language = row.querySelector('select[name*="[language]"]');
        const name = (language && language.name) || '';
        const match = name.match(/items\[(\d+)\]/);
        return match ? match[1] : null;
    }

    function setIncompleteRowsDisabled(disabled) {
        doneRows().forEach(function (row) {
            if (rowFilled(row)) return;
            row.querySelectorAll('select, input, textarea, button').forEach(function (el) {
                el.disabled = !!disabled;
            });
        });
    }

    function pruneDraftForItemIds(itemIds) {
        const draft = readDraft();
        if (!draft || !draft.items) return;
        (itemIds || []).forEach(function (id) {
            delete draft.items[String(id)];
        });
        if (Object.keys(draft.items).length === 0) {
            clearDraft();
            return;
        }
        try {
            sessionStorage.setItem(draftKey, JSON.stringify({
                savedAt: Date.now(),
                items: draft.items,
            }));
        } catch (e) {}
    }

    function syncDoneState() {
        const open = submitBtn && submitBtn.getAttribute('data-open') === '1';
        const complete = completeRows();
        const partial = partialRows();
        const ready = complete.length > 0 && partial.length === 0;
        if (submitBtn) {
            submitBtn.disabled = !(open && ready);
            const label = complete.length === 1
                ? 'Done — add 1 filled site & notify publisher'
                : ('Done — add ' + complete.length + ' filled sites & notify publisher');
            submitBtn.textContent = complete.length > 0
                ? label
                : 'Done — add filled sites & notify publisher';
        }
        if (hint) {
            hint.classList.toggle('d-none', ready);
            if (partial.length > 0) {
                hint.textContent = 'Finish or clear incomplete rows first. You can submit the '
                    + complete.length + ' complete block(s) after that.';
            } else if (complete.length === 0) {
                hint.textContent = 'Fill at least one complete block (Language, Country, DA, DR, Traffic, Niches) before Done.';
            } else {
                hint.textContent = '';
            }
        }
    }

    let draftTimer = null;
    function scheduleDraftSave() {
        clearTimeout(draftTimer);
        draftTimer = setTimeout(writeDraft, 300);
    }

    restoreDraftIfNeeded();

    function clampScoreInput(el) {
        if (!el || el.type !== 'number') return;
        // Only DA/DR score fields — never Traffic (monthly visitors can be millions/billions).
        if (el.hasAttribute('data-traffic-input') || !el.hasAttribute('data-score-clamp')) return;
        const max = Number(el.getAttribute('data-score-clamp') || el.max || 100);
        const raw = String(el.value ?? '').trim();
        if (raw === '') return;
        let n = Number(raw);
        if (Number.isNaN(n)) {
            el.value = '';
            return;
        }
        n = Math.round(n);
        if (n < 0) n = 0;
        if (n > max) n = max;
        if (String(n) !== raw) {
            el.value = String(n);
        }
    }

    form.querySelectorAll('[data-score-clamp]').forEach(function (el) {
        const max = String(el.getAttribute('data-score-clamp') || '100');
        el.setAttribute('min', '0');
        el.setAttribute('max', max);
        el.setAttribute('step', '1');
        el.addEventListener('input', function () { clampScoreInput(el); });
        el.addEventListener('blur', function () { clampScoreInput(el); });
    });

    // Keep Traffic unbounded by the DA/DR 0–100 score clamp.
    form.querySelectorAll('[data-traffic-input]').forEach(function (el) {
        el.setAttribute('min', '0');
        el.setAttribute('max', '4294967295');
        el.setAttribute('step', '1');
        el.removeAttribute('data-score-clamp');
    });

    form.addEventListener('input', function (e) {
        clampScoreInput(e.target);
        syncDoneState();
        scheduleDraftSave();
    });
    form.addEventListener('change', function (e) {
        clampScoreInput(e.target);
        syncDoneState();
        scheduleDraftSave();
    });

    form.addEventListener('submit', function (e) {
        // Dedicated flag so shared slb-confirm.js cannot clear imperative allows.
        if (form.dataset.slbBulkAllowSubmit === '1') {
            delete form.dataset.slbBulkAllowSubmit;
            const submittedIds = (form.dataset.slbBulkSubmittedIds || '')
                .split(',')
                .map(function (v) { return v.trim(); })
                .filter(Boolean);
            delete form.dataset.slbBulkSubmittedIds;
            pruneDraftForItemIds(submittedIds);
            return;
        }

        const complete = completeRows();
        const partial = partialRows();
        if (complete.length === 0 || partial.length > 0) {
            e.preventDefault();
            syncDoneState();
            if (partial.length > 0) {
                const firstPartial = rowFields(partial[0]).find((el) => !fieldFilled(el));
                if (firstPartial) {
                    firstPartial.focus();
                    firstPartial.classList.add('is-invalid');
                }
                slbAlert({
                    icon: 'warning',
                    title: 'Finish incomplete blocks',
                    text: 'Each started row must be fully filled, or clear it. Then submit the complete block(s). Empty rows can wait for later.',
                });
            } else {
                const firstEmpty = fields().find((el) => !fieldFilled(el));
                if (firstEmpty) {
                    firstEmpty.focus();
                    firstEmpty.classList.add('is-invalid');
                }
                slbAlert({
                    icon: 'warning',
                    title: 'Fill at least one block',
                    text: 'Fill Language, Country, DA, DR, Traffic and Niches for at least one website, then click Done. Other rows can stay empty for later.',
                });
            }
            return false;
        }

        const count = complete.length;
        const remaining = doneRows().length - count;
        const submittedIds = complete.map(rowItemId).filter(Boolean);
        e.preventDefault();
        const confirmFn = window.slbConfirm({
            title: 'Seed draft sites?',
            text: remaining > 0
                ? ('Add ' + count + ' complete draft site(s) now and notify the publisher? ' + remaining + ' unfinished row(s) will stay pending.')
                : ('Add ' + count + ' draft site(s) to this publisher’s Pending sites and notify them?'),
            confirmText: 'Add drafts',
            icon: 'question',
        });

        confirmFn.then(function (ok) {
            if (!ok) {
                setIncompleteRowsDisabled(false);
                return;
            }
            setIncompleteRowsDisabled(true);
            form.dataset.slbBulkSubmittedIds = submittedIds.join(',');
            form.dataset.slbBulkAllowSubmit = '1';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                HTMLFormElement.prototype.submit.call(form);
            }
        });
    });

    syncDoneState();
})();

document.querySelectorAll('.bulk-draft-delete').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        const id = this.getAttribute('data-site-id');
        const name = this.getAttribute('data-site-name') || 'this site';
        const ok = await window.slbConfirm({
                title: 'Delete draft site?',
                text: 'Delete draft "' + name + '"? This removes the wrong seed. History of the delete is kept.',
                confirmText: 'Delete draft',
                danger: true,
            });
        if (!ok) {
            return;
        }
        this.disabled = true;
        try {
            const res = await fetch(@json(staff_base_path() . '/sites') + '/' + id, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': @json(csrf_token()),
                    'Accept': 'application/json',
                },
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok || !data.success) {
                if (window.slbAlert) { await window.slbAlert({ icon: 'error', title: data.message || 'Could not delete site.' }); } else { alert(data.message || 'Could not delete site.'); }
                this.disabled = false;
                return;
            }
            location.reload();
        } catch (e) {
            if (window.slbAlert) { await window.slbAlert({ icon: 'error', title: 'Could not delete site.' }); } else { alert('Could not delete site.'); }
            this.disabled = false;
        }
    });
});
</script>
@endsection
