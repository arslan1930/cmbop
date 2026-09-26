@extends(staff_layout())

@section('content')
<div class="container-fluid bulk-request-show">
    <div class="bulk-request-show__header">
        <a href="{{ staff_route('bulk-site-requests.index') }}" class="small text-muted text-decoration-none">
            ← Bulk requests
        </a>
        <h3 class="bulk-request-show__title">Bulk request #{{ $bulkRequest->id }}</h3>
        <p class="text-muted small mb-0">
            Publisher: <strong>{{ $bulkRequest->publisher?->name ?? 'Unknown' }}</strong>
            ({{ $bulkRequest->publisher?->email ?? '—' }})
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

    <div class="row g-3 align-items-start bulk-request-layout">
        <div class="col-lg-4 bulk-request-sidebar">
            <div class="card border-0 shadow-sm bulk-request-note">
                <div class="card-body">
                    <h6 class="fw-semibold">Publisher note</h6>
                    <p class="small mb-0">{{ $bulkRequest->publisher_note ?: '—' }}</p>
                </div>
            </div>

            <div class="card border-0 shadow-sm bulk-request-ops">
                <div class="card-body">
                    <h6 class="fw-semibold">Ops actions</h6>
                    <form method="POST" action="{{ staff_route('bulk-site-requests.notes', $bulkRequest, false) }}">
                        @csrf
                        <label class="form-label small mb-1">Internal notes</label>
                        <textarea name="admin_notes" class="form-control form-control-sm" rows="3">{{ old_text('admin_notes', $bulkRequest->admin_notes) }}</textarea>
                        <button type="submit" class="btn btn-sm btn-outline-secondary mt-2">Save notes</button>
                    </form>

                    @if($bulkRequest->canMarkSheetSent())
                        <form method="POST" action="{{ staff_route('bulk-site-requests.sheet-sent', $bulkRequest, false) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                Mark sheet emailed (optional)
                            </button>
                        </form>
                    @endif
                    @if($bulkRequest->canCancel())
                        <form method="POST" action="{{ staff_route('bulk-site-requests.cancel', $bulkRequest, false) }}"
                              class="bulk-request-cancel">
                            @csrf
                            <input type="hidden" name="reason" value="">
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">Cancel request</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm bulk-request-history">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">History</h6>
                    <p class="small text-muted mb-2">Append-only audit trail. Cannot be deleted.</p>
                    <div class="bulk-history-list">
                        @forelse($history as $entry)
                            <div class="bulk-history-item">
                                <div class="fw-semibold">{{ marketing_task_label($entry->action) }}</div>
                                <div class="text-muted">{{ $entry->description }}</div>
                                @php
                                    $historyNote = \App\Support\MarketingHistoryDisplay::reason($entry);
                                @endphp
                                @if($historyNote)
                                    <div class="text-muted mt-1" data-history-reason>{{ \App\Support\MarketingHistoryDisplay::reasonLabel($entry) }}: {{ $historyNote }}</div>
                                @endif
                                <div class="bulk-history-meta text-muted">
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

        <div class="col-lg-8 bulk-request-main">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Publisher submitted (URL + price only)</h6>
                    <p class="small text-muted mb-3">
                        Review each website, then fill <strong>Language, Country, DA, DR, Traffic, and Niches</strong> per row before Done.
                        Finished rows go <strong>active</strong> on the publisher’s account and stay <strong>unverified</strong>. The publisher is notified. They do not fill details again.
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
                                            @elseif($bulkRequest->isCancelled())
                                                <span class="badge text-bg-secondary">Cancelled</span>
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
        </div>
    </div>

    <div class="card border-0 shadow-sm border-primary-subtle bulk-request-done">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Done — publish now, or send to the publisher for review</h6>
                    <p class="small text-muted mb-3">
                        <strong>{{ $pendingItems->count() }}</strong> website(s) still pending
                        (publisher + marketer share a {{ \App\Models\BulkSiteRequest::MAX_SITES_PER_REQUEST }}-site batch limit).
                        Fill every required field on a row — language, country, DA, DR, traffic, niches, sample article, turnaround, publication time, link type, listing tag, description, and site image — then choose one action for the filled rows.
                        <strong>Publish now</strong> puts them live (active, not verified) and tells the publisher they are on the account.
                        <strong>Send for review</strong> leaves them off the catalog. The publisher checks them and submits them, and they then show in Sites → Needs review.
                        Either way the site is marked Bulk request. Unfilled rows stay here.
                        Sensitive-topic prices are required only when that topic is offered.
                        Delete a row you will not add — those sites leave this batch and the publisher gets one note for all removed sites.
                        The quality bar is DA ≥ {{ \App\Models\Site::GOOD_MIN_DA }}, DR ≥ {{ \App\Models\Site::GOOD_MIN_DR }}, and traffic ≥ {{ number_format(\App\Models\Site::GOOD_MIN_TRAFFIC) }}. Publish now is allowed below that bar and the site goes live unverified. Send for review does not put it live.
                    </p>

                    @if($errors->any())
                        @php
                            $hasItemErrors = collect($errors->keys())->contains(
                                fn ($key) => $key === 'items' || str_starts_with((string) $key, 'items.')
                            );
                            $itemError = $hasItemErrors
                                ? collect($errors->messages())->first(
                                    fn ($msgs, $key) => $key === 'items' || str_starts_with((string) $key, 'items.')
                                )
                                : null;
                            $alertBody = is_array($itemError)
                                ? ($itemError[0] ?? $errors->first())
                                : $errors->first();
                        @endphp
                        <div class="alert alert-danger py-2 small">
                            <strong>
                                @if($errors->has('rejection_note') && ! $hasItemErrors)
                                    Add a publisher note.
                                @else
                                    Finish the boxes first.
                                @endif
                            </strong>
                            {{ $alertBody }}
                        </div>
                    @endif

                    @if($pendingItems->isEmpty())
                        <div class="form-text">
                            @if($bulkRequest->sites->isNotEmpty())
                                All submitted rows are already added.
                            @else
                                No pending websites left on this request.
                            @endif
                        </div>
                    @else
                        <form method="POST"
                              action="{{ staff_route('bulk-site-requests.done', $bulkRequest, false) }}"
                              id="bulkDoneForm"
                              enctype="multipart/form-data"
                              novalidate
                              data-min-da="{{ \App\Models\Site::GOOD_MIN_DA }}"
                              data-min-dr="{{ \App\Models\Site::GOOD_MIN_DR }}"
                              data-min-traffic="{{ \App\Models\Site::GOOD_MIN_TRAFFIC }}">
                            @csrf
                            @php
                                $oldRejectedIds = collect(old('rejected_item_ids', []))
                                    ->map(fn ($id) => (int) $id)
                                    ->filter()
                                    ->unique()
                                    ->values()
                                    ->all();
                            @endphp
                            <div id="bulkRejectedIds">
                                @foreach($oldRejectedIds as $rejectedId)
                                    <input type="hidden" name="rejected_item_ids[]" value="{{ $rejectedId }}">
                                @endforeach
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2" data-bulk-row-tools>
                                <input type="search" id="bulkRowFilter" class="form-control form-control-sm" style="max-width:16rem;" placeholder="Filter domain or URL" aria-label="Filter rows by domain or URL">
                                <div class="btn-group btn-group-sm" role="group" aria-label="Filter rows by status">
                                    <button type="button" class="btn btn-outline-secondary active" data-bulk-chip-filter="all">All</button>
                                    <button type="button" class="btn btn-outline-secondary" data-bulk-chip-filter="empty">Empty</button>
                                    <button type="button" class="btn btn-outline-secondary" data-bulk-chip-filter="incomplete">Incomplete</button>
                                    <button type="button" class="btn btn-outline-secondary" data-bulk-chip-filter="ready">Ready</button>
                                    <button type="button" class="btn btn-outline-secondary" data-bulk-chip-filter="below">Below bar</button>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk-expand-all>Expand all</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk-collapse-all>Collapse all</button>
                                <span class="small text-muted">Also copy when applying:</span>
                                <label class="small mb-0"><input type="checkbox" data-bulk-apply-opt="niches"> Niches</label>
                                <label class="small mb-0"><input type="checkbox" data-bulk-apply-opt="description"> Description</label>
                                <label class="small mb-0"><input type="checkbox" data-bulk-apply-opt="sensitive"> Sensitive</label>
                                <label class="small mb-0"><input type="checkbox" data-bulk-apply-opt="homepage"> Homepage</label>
                                <label class="small mb-0"><input type="checkbox" data-bulk-apply-opt="social"> Social</label>
                            </div>
                            <div class="bulk-done-table-wrap bulk-done-list admin-contained-scroll mb-3">
                                @php $openedFirstEmpty = false; @endphp
                                @foreach($pendingItems as $item)
                                    @php
                                        $old = old('items.'.$item->id, null);
                                        if (! is_array($old)) {
                                            $draftItems = is_array($textDraft['items'] ?? null) ? $textDraft['items'] : [];
                                            $old = $draftItems[$item->id] ?? $draftItems[(string) $item->id] ?? [];
                                        }
                                        if (! is_array($old)) {
                                            $old = [];
                                        }
                                        $oldCategories = $old['categories'] ?? '';
                                        if (is_array($oldCategories)) {
                                            $oldCategories = implode('|', $oldCategories);
                                        }
                                        $uid = 'done'.$item->id;
                                        $oldCountry = strtolower((string) ($old['country'] ?? ''));
                                        $oldLanguage = strtolower((string) ($old['language'] ?? ''));
                                        $isRejected = in_array((int) $item->id, $oldRejectedIds, true);
                                        $keptCover = $keptCovers[(int) $item->id] ?? $keptCovers[(string) $item->id] ?? null;
                                        $descriptionReady = mb_strlen(trim((string) ($old['description'] ?? ''))) >= 50;
                                        $doneChecks = [
                                            trim((string) ($old['country'] ?? '')) !== '',
                                            trim((string) ($old['language'] ?? '')) !== '',
                                            trim((string) ($old['da'] ?? '')) !== '',
                                            trim((string) ($old['dr'] ?? '')) !== '',
                                            trim((string) ($old['traffic'] ?? '')) !== '',
                                            trim((string) $oldCategories) !== '',
                                            trim((string) ($old['example_url'] ?? '')) !== '',
                                            trim((string) ($old['turnaround_time'] ?? '')) !== '',
                                            trim((string) ($old['publication_time'] ?? '')) !== '',
                                            trim((string) ($old['link_type'] ?? '')) !== '',
                                            trim((string) ($old['site_tag'] ?? '')) !== '',
                                            $descriptionReady,
                                            is_array($keptCover),
                                        ];
                                        $filledCount = count(array_filter($doneChecks));
                                        $doneTrackTotal = count($doneChecks);
                                        $itemErrorPrefix = 'items.'.$item->id.'.';
                                        $rowHasErrors = collect($errors->keys())->contains(
                                            fn ($key) => $key === 'items.'.$item->id || str_starts_with((string) $key, $itemErrorPrefix)
                                        );
                                        $openAsFirstEmpty = ! $isRejected && $filledCount === 0 && ! $rowHasErrors && ! $openedFirstEmpty;
                                        $rowOpen = ! $isRejected && ($rowHasErrors || $filledCount > 0 || $openAsFirstEmpty);
                                        if ($openAsFirstEmpty) {
                                            $openedFirstEmpty = true;
                                        }
                                        $chipLabel = $filledCount === 0
                                            ? 'Empty'
                                            : ($filledCount >= $doneTrackTotal ? 'Ready' : $filledCount.'/'.$doneTrackTotal.' filled');
                                        $chipClass = $filledCount === 0
                                            ? 'is-empty'
                                            : ($filledCount >= $doneTrackTotal ? 'is-ready' : 'is-partial');
                                        $oldDa = trim((string) ($old['da'] ?? ''));
                                        $oldDr = trim((string) ($old['dr'] ?? ''));
                                        $oldTraffic = trim((string) ($old['traffic'] ?? ''));
                                        $metricsFilled = $oldDa !== '' && $oldDr !== '' && $oldTraffic !== '';
                                        $belowQuality = $metricsFilled
                                            && ((int) $oldDa < \App\Models\Site::GOOD_MIN_DA
                                                || (int) $oldDr < \App\Models\Site::GOOD_MIN_DR
                                                || (int) $oldTraffic < \App\Models\Site::GOOD_MIN_TRAFFIC);
                                    @endphp
                                    <details class="bulk-done-row" data-bulk-done-row
                                             data-item-id="{{ $item->id }}"
                                             data-domain="{{ $item->domain }}"
                                             data-url="{{ $item->site_url }}"
                                             @class(['d-none' => $isRejected])
                                             @if($isRejected) data-bulk-rejected="1" @endif
                                             @if($rowOpen) open @endif>
                                        <summary class="bulk-done-row__summary">
                                            <span class="bulk-done-row__identity">
                                                <span class="fw-semibold text-break">{{ $item->domain }}</span>
                                                <a class="small text-muted text-break" href="{{ $item->site_url }}" target="_blank" rel="noopener noreferrer">
                                                    {{ $item->site_url }}
                                                </a>
                                            </span>
                                            <span class="bulk-done-row__meta">
                                                <span class="text-nowrap">€{{ number_format((float) $item->price, 2) }}</span>
                                                <span class="bulk-done-row__chip {{ $chipClass }}" data-bulk-done-chip>{{ $chipLabel }}</span>
                                                <span class="bulk-done-row__chip is-below-bar{{ $belowQuality ? '' : ' d-none' }}" data-bulk-quality-chip>Below bar</span>
                                            </span>
                                        </summary>
                                        <div class="bulk-done-row__body">
                                            @if(!empty($occupyingMessages[(int) $item->id]))
                                                <div class="alert alert-danger py-2 px-3 small mb-2" role="alert">
                                                    {{ $occupyingMessages[(int) $item->id] }}
                                                </div>
                                            @endif
                                            <div class="bulk-done-row__fields">
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-name-{{ $item->id }}">Site name</label>
                                                    <input id="bulk-done-name-{{ $item->id }}"
                                                           name="items[{{ $item->id }}][site_name]"
                                                           class="form-control @error('items.'.$item->id.'.site_name') is-invalid @enderror"
                                                           value="{{ $old['site_name'] ?? $item->domain }}"
                                                           data-bulk-default="{{ $item->domain }}"
                                                           maxlength="255"
                                                           @disabled($isRejected)>
                                                    @error('items.'.$item->id.'.site_name')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-price-{{ $item->id }}">Price (€)</label>
                                                    <input id="bulk-done-price-{{ $item->id }}"
                                                           type="number"
                                                           name="items[{{ $item->id }}][price]"
                                                           class="form-control @error('items.'.$item->id.'.price') is-invalid @enderror"
                                                           value="{{ $old['price'] ?? $item->price }}"
                                                           data-bulk-default="{{ $item->price }}"
                                                           min="0"
                                                           max="99999999.99"
                                                           step="0.01"
                                                           @disabled($isRejected)>
                                                    @error('items.'.$item->id.'.price')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-country-{{ $item->id }}">Country <span class="text-danger">*</span></label>
                                                    <select id="bulk-done-country-{{ $item->id }}"
                                                            name="items[{{ $item->id }}][country]"
                                                            class="form-select @error('items.'.$item->id.'.country') is-invalid @enderror"
                                                            required
                                                            data-bulk-required
                                                            data-bulk-country
                                                            @disabled($isRejected)>
                                                        <option value="">Select…</option>
                                                        @foreach($countries as $country)
                                                            <option value="{{ strtolower($country->code) }}"
                                                                @selected($oldCountry === strtolower($country->code))>
                                                                {{ $country->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    @error('items.'.$item->id.'.country')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-language-{{ $item->id }}">Language <span class="text-danger">*</span></label>
                                                    <select id="bulk-done-language-{{ $item->id }}"
                                                            name="items[{{ $item->id }}][language]"
                                                            class="form-select @error('items.'.$item->id.'.language') is-invalid @enderror"
                                                            required
                                                            data-bulk-required
                                                            data-bulk-language
                                                            @disabled($isRejected || $oldCountry === '')>
                                                        <option value="">{{ $oldCountry === '' ? 'Select country first' : 'Select…' }}</option>
                                                        @if($oldCountry !== '')
                                                            @foreach(($countryLanguageMap[$oldCountry] ?? []) as $lang)
                                                                <option value="{{ $lang['code'] }}"
                                                                    @selected($oldLanguage === strtolower((string) $lang['code']))>
                                                                    {{ $lang['name'] }}
                                                                </option>
                                                            @endforeach
                                                        @endif
                                                    </select>
                                                    @error('items.'.$item->id.'.language')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-da-{{ $item->id }}">DA <span class="text-danger">*</span></label>
                                                    <input type="number"
                                                           id="bulk-done-da-{{ $item->id }}"
                                                           name="items[{{ $item->id }}][da]"
                                                           class="form-control @error('items.'.$item->id.'.da') is-invalid @enderror"
                                                           placeholder="0–100"
                                                           min="0" max="100" step="1"
                                                           inputmode="numeric"
                                                           value="{{ $old['da'] ?? '' }}"
                                                           required
                                                           data-bulk-required
                                                           data-score-clamp="100"
                                                           @disabled($isRejected)>
                                                    @error('items.'.$item->id.'.da')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-dr-{{ $item->id }}">DR <span class="text-danger">*</span></label>
                                                    <input type="number"
                                                           id="bulk-done-dr-{{ $item->id }}"
                                                           name="items[{{ $item->id }}][dr]"
                                                           class="form-control @error('items.'.$item->id.'.dr') is-invalid @enderror"
                                                           placeholder="0–100"
                                                           min="0" max="100" step="1"
                                                           inputmode="numeric"
                                                           value="{{ $old['dr'] ?? '' }}"
                                                           required
                                                           data-bulk-required
                                                           data-score-clamp="100"
                                                           @disabled($isRejected)>
                                                    @error('items.'.$item->id.'.dr')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field bulk-done-field--traffic">
                                                    <label class="form-label" for="bulk-done-traffic-{{ $item->id }}">Traffic <span class="text-danger">*</span></label>
                                                    {{-- Traffic is monthly visitors (can be millions/billions). Never clamp like DA/DR. --}}
                                                    <input type="number"
                                                           id="bulk-done-traffic-{{ $item->id }}"
                                                           name="items[{{ $item->id }}][traffic]"
                                                           class="form-control @error('items.'.$item->id.'.traffic') is-invalid @enderror"
                                                           placeholder="e.g. 1500000"
                                                           min="0"
                                                           max="4294967295"
                                                           step="1"
                                                           inputmode="numeric"
                                                           value="{{ $old['traffic'] ?? '' }}"
                                                           required
                                                           data-bulk-required
                                                           data-traffic-input
                                                           @disabled($isRejected)>
                                                    @error('items.'.$item->id.'.traffic')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field bulk-done-field--niches bulk-done-niches-cell">
                                                    <label class="form-label" for="categoryInput-{{ $uid }}">Niches <span class="text-danger">*</span></label>
                                                    <input type="hidden"
                                                           name="items[{{ $item->id }}][categories]"
                                                           id="selectedCategories-{{ $uid }}"
                                                           value="{{ $oldCategories }}"
                                                           data-bulk-required
                                                           class="@error('items.'.$item->id.'.categories') is-invalid @enderror"
                                                           @disabled($isRejected)>
                                                    <div class="multi-select-wrapper" id="categoryWrapper-{{ $uid }}" data-multi-select="category">
                                                        <div class="multi-select-input"
                                                             id="categoryInput-{{ $uid }}"
                                                             role="button"
                                                             tabindex="0"
                                                             aria-haspopup="listbox"
                                                             aria-expanded="false"
                                                             aria-label="Select niches for {{ $item->domain }}">
                                                            <span class="multi-select-placeholder">Select niches…</span>
                                                        </div>
                                                        <div class="multi-select-dropdown" id="categoryDropdown-{{ $uid }}" role="listbox" aria-multiselectable="true">
                                                            <div class="multi-select-search">
                                                                <input type="text" placeholder="Type to search niches…" id="categorySearch-{{ $uid }}" autocomplete="off" aria-label="Search niches">
                                                            </div>
                                                            <div class="multi-select-options" id="categoryOptions-{{ $uid }}">
                                                                @foreach($categories as $categoryName)
                                                                    <div class="multi-select-option"
                                                                         role="option"
                                                                         data-value="{{ $categoryName }}"
                                                                         data-label="{{ $categoryName }}">{{ $categoryName }}</div>
                                                                @endforeach
                                                            </div>
                                                            <div class="multi-select-empty d-none" id="categoryEmpty-{{ $uid }}" role="status">No categories found</div>
                                                        </div>
                                                    </div>
                                                    @error('items.'.$item->id.'.categories')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-example-{{ $item->id }}">Sample article <span class="text-danger">*</span></label>
                                                    <input id="bulk-done-example-{{ $item->id }}"
                                                           type="url"
                                                           name="items[{{ $item->id }}][example_url]"
                                                           class="form-control @error('items.'.$item->id.'.example_url') is-invalid @enderror"
                                                           value="{{ $old['example_url'] ?? '' }}"
                                                           placeholder="https://…/sample-post"
                                                           required
                                                           data-bulk-required
                                                           @disabled($isRejected)>
                                                    @error('items.'.$item->id.'.example_url')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-turnaround-{{ $item->id }}">Turnaround <span class="text-danger">*</span></label>
                                                    <select id="bulk-done-turnaround-{{ $item->id }}"
                                                            name="items[{{ $item->id }}][turnaround_time]"
                                                            class="form-select @error('items.'.$item->id.'.turnaround_time') is-invalid @enderror"
                                                            required
                                                            data-bulk-required
                                                            @disabled($isRejected)>
                                                        <option value="">Select…</option>
                                                        @foreach(['24h' => '24 Hours', '48h' => '48 Hours', '3days' => '3 Days', '5days' => '5 Days', '7days' => '7 Days'] as $val => $label)
                                                            <option value="{{ $val }}" @selected(($old['turnaround_time'] ?? '') === $val)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('items.'.$item->id.'.turnaround_time')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-publication-{{ $item->id }}">Publication <span class="text-danger">*</span></label>
                                                    <select id="bulk-done-publication-{{ $item->id }}"
                                                            name="items[{{ $item->id }}][publication_time]"
                                                            class="form-select @error('items.'.$item->id.'.publication_time') is-invalid @enderror"
                                                            required
                                                            data-bulk-required
                                                            @disabled($isRejected)>
                                                        <option value="">Select…</option>
                                                        @foreach(['6months' => '6 Months', '1year' => '1 Year', 'permanent' => 'Permanent'] as $val => $label)
                                                            <option value="{{ $val }}" @selected(($old['publication_time'] ?? '') === $val)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('items.'.$item->id.'.publication_time')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-link-{{ $item->id }}">Link type <span class="text-danger">*</span></label>
                                                    <select id="bulk-done-link-{{ $item->id }}"
                                                            name="items[{{ $item->id }}][link_type]"
                                                            class="form-select @error('items.'.$item->id.'.link_type') is-invalid @enderror"
                                                            required
                                                            data-bulk-required
                                                            @disabled($isRejected)>
                                                        <option value="">Select…</option>
                                                        <option value="dofollow" @selected(($old['link_type'] ?? '') === 'dofollow')>DoFollow</option>
                                                        <option value="nofollow" @selected(($old['link_type'] ?? '') === 'nofollow')>NoFollow</option>
                                                    </select>
                                                    @error('items.'.$item->id.'.link_type')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field">
                                                    <label class="form-label" for="bulk-done-tag-{{ $item->id }}">Listing tag <span class="text-danger">*</span></label>
                                                    <select id="bulk-done-tag-{{ $item->id }}"
                                                            name="items[{{ $item->id }}][site_tag]"
                                                            class="form-select @error('items.'.$item->id.'.site_tag') is-invalid @enderror"
                                                            required
                                                            data-bulk-required
                                                            @disabled($isRejected)>
                                                        <option value="">Select…</option>
                                                        <option value="none" @selected(($old['site_tag'] ?? '') === 'none')>No tags</option>
                                                        @foreach(\App\Support\SiteTag::LABELS as $value => $label)
                                                            <option value="{{ $value }}" @selected(($old['site_tag'] ?? '') === $value)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('items.'.$item->id.'.site_tag')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field bulk-done-field--description">
                                                    <label class="form-label" for="bulk-done-description-{{ $item->id }}">Description <span class="text-danger">*</span></label>
                                                    <textarea id="bulk-done-description-{{ $item->id }}"
                                                              name="items[{{ $item->id }}][description]"
                                                              class="form-control @error('items.'.$item->id.'.description') is-invalid @enderror"
                                                              rows="3"
                                                              maxlength="5000"
                                                              minlength="50"
                                                              placeholder="Shown to advertisers. At least 50 characters."
                                                              required
                                                              data-bulk-required
                                                              @disabled($isRejected)>{{ $old['description'] ?? '' }}</textarea>
                                                    @error('items.'.$item->id.'.description')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field bulk-done-field--wide">
                                                    <label class="form-label" for="bulk-done-image-{{ $item->id }}">Site image <span class="text-danger">*</span></label>
                                                    <input id="bulk-done-image-{{ $item->id }}"
                                                           type="file"
                                                           name="items[{{ $item->id }}][site_image]"
                                                           class="form-control @error('items.'.$item->id.'.site_image') is-invalid @enderror"
                                                           accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp"
                                                           @required(! is_array($keptCover))
                                                           data-bulk-required
                                                           @if(is_array($keptCover)) data-bulk-kept="1" @endif
                                                           @disabled($isRejected)>
                                                    <div class="form-text">JPEG, PNG, GIF, or WebP, up to {{ \App\Support\SiteImageUpload::maxMegabytesLabel() }} MB. This cover is saved with the site.</div>
                                                    @if(is_array($keptCover))
                                                        <input type="hidden" name="items[{{ $item->id }}][kept_image]" value="{{ $keptCover['path'] }}" data-bulk-kept-image>
                                                        <div class="form-text">Saved cover: {{ $keptCover['name'] }}. You do not need to upload it again unless you want a different image.</div>
                                                    @endif
                                                    @error('items.'.$item->id.'.site_image')
                                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="bulk-done-field bulk-done-field--wide">
                                                    <span class="form-label">Sensitive topics</span>
                                                    <div class="d-flex flex-wrap gap-3">
                                                        @foreach(['crypto' => 'Crypto', 'trading' => 'Trading', 'CBD' => 'CBD', 'forex' => 'Forex'] as $topic => $label)
                                                            <label class="small mb-0">
                                                                <input type="checkbox"
                                                                       name="items[{{ $item->id }}][sensitive][{{ $topic }}]"
                                                                       value="1"
                                                                       @checked(old('items.'.$item->id.'.sensitive.'.$topic))
                                                                       @disabled($isRejected)>
                                                                {{ $label }}
                                                                <input type="number"
                                                                       name="items[{{ $item->id }}][price_sensitive][{{ $topic }}]"
                                                                       class="form-control form-control-sm mt-1 @error('items.'.$item->id.'.price_sensitive.'.$topic) is-invalid @enderror"
                                                                       value="{{ old('items.'.$item->id.'.price_sensitive.'.$topic) }}"
                                                                       min="0"
                                                                       step="0.01"
                                                                       placeholder="Extra price"
                                                                       @disabled($isRejected)>
                                                                @error('items.'.$item->id.'.price_sensitive.'.$topic)
                                                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                                                @enderror
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>
                                                <div class="bulk-done-field bulk-done-field--wide">
                                                    <span class="form-label">Homepage placement</span>
                                                    <div class="d-flex flex-wrap gap-3">
                                                        @foreach($homepageDays as $days)
                                                            <label class="small mb-0">
                                                                <input type="checkbox"
                                                                       name="items[{{ $item->id }}][homepage][{{ $days }}]"
                                                                       value="1"
                                                                       data-bulk-homepage
                                                                       @checked(old('items.'.$item->id.'.homepage.'.$days, $old['homepage'][$days] ?? $old['homepage'][(string) $days] ?? false))
                                                                       @disabled($isRejected)>
                                                                {{ $days }} day{{ (int) $days > 1 ? 's' : '' }}
                                                                <input type="number"
                                                                       name="items[{{ $item->id }}][price_homepage][{{ $days }}]"
                                                                       class="form-control form-control-sm mt-1 @error('items.'.$item->id.'.price_homepage.'.$days) is-invalid @enderror"
                                                                       value="{{ old('items.'.$item->id.'.price_homepage.'.$days, $old['price_homepage'][$days] ?? $old['price_homepage'][(string) $days] ?? '') }}"
                                                                       min="0"
                                                                       step="0.01"
                                                                       placeholder="Fee (€) — 0 = free"
                                                                       data-bulk-homepage-price="{{ $days }}"
                                                                       @disabled($isRejected)>
                                                                @error('items.'.$item->id.'.price_homepage.'.$days)
                                                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                                                @enderror
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>
                                                <div class="bulk-done-field bulk-done-field--wide">
                                                    <span class="form-label">Social sharing</span>
                                                    <div class="d-flex flex-wrap gap-3">
                                                        @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X'] as $channel => $label)
                                                            <label class="small mb-0">
                                                                <input type="checkbox"
                                                                       name="items[{{ $item->id }}][social][{{ $channel }}]"
                                                                       value="1"
                                                                       data-bulk-social="{{ $channel }}"
                                                                       @checked(old('items.'.$item->id.'.social.'.$channel, $old['social'][$channel] ?? false))
                                                                       @disabled($isRejected)>
                                                                {{ $label }}
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="alert alert-warning border-0 py-2 px-3 small mb-0{{ $belowQuality ? '' : ' d-none' }}"
                                                 data-bulk-quality-warn
                                                 role="status">
                                                These metrics are below the quality bar. You can still submit this row. Publish now puts it live unverified. Send for review leaves it off the catalog.
                                            </div>
                                            <div class="bulk-done-row__actions">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk-clear-row @disabled($isRejected)>
                                                    Clear row
                                                </button>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary"
                                                        data-bulk-copy-above
                                                        @disabled($loop->first || $isRejected)>
                                                    Copy from row above
                                                </button>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary"
                                                        data-bulk-apply-empty
                                                        @disabled($isRejected)>
                                                    Apply to empty rows
                                                </button>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-danger bulk-done-reject"
                                                        data-bulk-reject-row
                                                        @disabled($isRejected)>
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    </details>
                                @endforeach
                            </div>

                            <div id="bulkRejectionNoteWrap" class="mb-3{{ $oldRejectedIds === [] && ! $errors->has('rejection_note') ? ' d-none' : '' }}">
                                <label for="rejection_note" class="form-label small fw-semibold">Note to publisher (removed sites)</label>
                                <textarea name="rejection_note"
                                          id="rejection_note"
                                          class="form-control @error('rejection_note') is-invalid @enderror"
                                          rows="3"
                                          minlength="10"
                                          maxlength="1000"
                                          placeholder="Tell the publisher why these sites were not added (10–1000 characters).">{{ old_text('rejection_note') }}</textarea>
                                @error('rejection_note')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                                <div class="form-text">Required when you delete one or more sites. The publisher gets this one note for all removed sites.</div>
                            </div>

                            <div id="bulkDoneHint" class="alert alert-warning py-2 small mb-3" role="status">
                                Fill at least one complete block (country, language, DA, DR, traffic, niches, sample article, turnaround, publication, link type, listing tag, description of at least 50 characters, and a site image) before Done.
                            </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit"
                                name="done_mode"
                                value="publish"
                                id="bulkDoneSubmit"
                                class="btn btn-primary"
                                data-open="{{ $bulkRequest->canAddDraftSites() ? '1' : '0' }}"
                                disabled>
                            Publish filled sites now
                        </button>
                        <button type="submit"
                                name="done_mode"
                                value="review"
                                id="bulkReviewSubmit"
                                class="btn btn-outline-primary"
                                data-open="{{ $bulkRequest->canAddDraftSites() ? '1' : '0' }}"
                                disabled>
                            Send filled sites to publisher for review
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3 align-items-start bulk-request-lower">
        <div class="col-12 bulk-request-stack">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Advanced: seed with per-row metrics</h6>
                    <p class="small text-muted mb-3">
                        Optional paste when the listing details differ per site.
                        A cover image cannot be pasted. Valid rows fill the Done form below. Upload each cover there, then Done.
                        Columns: <code>url,price,da,dr,traffic,country,language,site_name,example_url,turnaround,publication,link_type,tag,niches,description</code>
                        Niches use <code>|</code> inside that column. The description is the rest of the line.
                        Turnaround is <code>24h</code>, <code>48h</code>, <code>3days</code>, <code>5days</code>, or <code>7days</code>.
                        Publication is <code>6months</code>, <code>1year</code>, or <code>permanent</code>.
                        Link type is <code>dofollow</code> or <code>nofollow</code>.
                        Tag is <code>none</code>, <code>sponsored</code>, <code>partner_material</code>, or <code>as_you_prefer</code>.
                        @if($pendingItems->isNotEmpty())
                            Only pending URL + price domains from this request can be seeded here.
                        @endif
                    </p>
                    @php
                        $seedStarter = $pendingItems->map(function ($item) {
                            return $item->site_url.','.$item->price.',0,0,0,country,lang,Site name,https://example.com/sample,3days,permanent,dofollow,as_you_prefer,Niche,Description of at least 50 characters about guest posts on this site.';
                        })->implode("\n");
                    @endphp
                    @if($seedStarter !== '')
                        <div class="small mb-2">
                            <span class="text-muted">Starter from pending URL + price (replace country/lang and metrics):</span>
                            <pre class="bg-light border rounded p-2 small mb-2 mt-1" id="bulkSeedStarter" style="max-height:8rem;overflow:auto;">{{ $seedStarter }}</pre>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkCopySeedStarter">Copy starter into box</button>
                        </div>
                    @endif
                    <form method="POST" action="{{ staff_route('bulk-site-requests.seed', $bulkRequest, false) }}">
                        @csrf
                        <textarea name="rows" id="bulkSeedRows" class="form-control font-monospace small @error('rows') is-invalid @enderror" rows="8"
                                  placeholder="https://example.com,99,40,45,12000,de,de,Example Blog,https://example.com/sample,3days,permanent,dofollow,as_you_prefer,Business &amp; Finance,Guest posts on this website stay published and the link remains dofollow for advertisers.">{{ old_text('rows', $seedStarter) }}</textarea>
                        @error('rows')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-outline-primary btn-sm mt-2" @disabled(! $bulkRequest->canAddDraftSites())>
                            Check pasted rows
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
                                        <td class="text-uppercase small">{{ $site->country }} / {{ $site->language }}</td>
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
<link href="{{ asset('assets/css/single-select.css') }}?v={{ @filemtime(public_path('assets/css/single-select.css')) ?: '1' }}" rel="stylesheet">
<script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="{{ asset('js/multi-select.js') }}?v={{ @filemtime(public_path('js/multi-select.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/single-select.js') }}?v={{ @filemtime(public_path('assets/js/single-select.js')) ?: '1' }}"></script>
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

    form.querySelectorAll('.bulk-done-row__summary a').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    });

    const submitBtn = document.getElementById('bulkDoneSubmit');
    const reviewBtn = document.getElementById('bulkReviewSubmit');
    const hint = document.getElementById('bulkDoneHint');
    const noteWrap = document.getElementById('bulkRejectionNoteWrap');
    const noteEl = document.getElementById('rejection_note');
    const rejectedBox = document.getElementById('bulkRejectedIds');
    const fields = () => Array.from(form.querySelectorAll('[data-bulk-done-row]:not([data-bulk-rejected="1"]) [data-bulk-required]'));
    const multiSelects = {};
    const prefills = {};
    const hasServerOld = @json((bool) old('items') || ! empty($textDraft['items'] ?? []));
    const draftUrl = @json(staff_route('bulk-site-requests.draft', $bulkRequest, false));
    const draftKey = @json('bulkDoneDraft:'.$bulkRequest->id.':'.auth()->id());
    const draftTtlMs = 24 * 60 * 60 * 1000;
    const countryLanguageMap = @json($countryLanguageMap ?? new \stdClass());
    const qualityMinDa = parseInt(form.getAttribute('data-min-da') || '30', 10);
    const qualityMinDr = parseInt(form.getAttribute('data-min-dr') || '30', 10);
    const qualityMinTraffic = parseInt(form.getAttribute('data-min-traffic') || '10000', 10);

    function syncBulkThemeSelect(select) {
        if (select && typeof select._bulkThemeSync === 'function') {
            select._bulkThemeSync();
        }
    }

    function syncBulkThemeSelects(root) {
        if (!root) return;
        root.querySelectorAll('select').forEach(syncBulkThemeSelect);
    }

    function mountBulkThemeSelect(select) {
        if (!select || select.closest('.bulk-theme-select')) return;
        const parent = select.parentNode;
        if (!parent) return;

        const wrap = document.createElement('div');
        wrap.className = 'single-select-wrapper bulk-theme-select';
        parent.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('visually-hidden');
        select.tabIndex = -1;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'single-select-input';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        const labelled = select.id ? document.querySelector('label[for="' + select.id + '"]') : null;
        const aria = (labelled ? labelled.textContent : select.getAttribute('aria-label') || 'Choose').replace(/\s+/g, ' ').trim();
        trigger.setAttribute('aria-label', aria);

        const valueEl = document.createElement('span');
        valueEl.className = 'single-select-value';
        const arrow = document.createElement('i');
        arrow.className = 'fa fa-chevron-down single-select-arrow';
        arrow.setAttribute('aria-hidden', 'true');
        trigger.appendChild(valueEl);
        trigger.appendChild(arrow);

        const dropdown = document.createElement('div');
        dropdown.className = 'single-select-dropdown';
        const searchWrap = document.createElement('div');
        searchWrap.className = 'single-select-search';
        const search = document.createElement('input');
        search.type = 'search';
        search.placeholder = 'Search…';
        search.autocomplete = 'off';
        search.setAttribute('aria-label', 'Search ' + aria);
        searchWrap.appendChild(search);
        const options = document.createElement('div');
        options.className = 'single-select-options';
        options.setAttribute('role', 'listbox');
        dropdown.appendChild(searchWrap);
        dropdown.appendChild(options);
        wrap.appendChild(trigger);
        wrap.appendChild(dropdown);

        function rebuild() {
            const current = String(select.value || '');
            options.innerHTML = '';
            Array.from(select.options).forEach(function (opt) {
                const el = document.createElement('div');
                const on = opt.value === current;
                el.className = 'single-select-option' + (on ? ' selected' : '');
                el.setAttribute('role', 'option');
                el.setAttribute('data-value', opt.value);
                el.setAttribute('data-label', opt.textContent || '');
                el.setAttribute('aria-selected', on ? 'true' : 'false');
                el.textContent = opt.textContent || '';
                options.appendChild(el);
            });
            const selected = select.options[select.selectedIndex];
            const label = selected ? String(selected.textContent || '').trim() : 'Select…';
            valueEl.textContent = label || 'Select…';
            valueEl.classList.toggle('single-select-placeholder', current === '');
            trigger.disabled = !!select.disabled;
            trigger.classList.toggle('is-invalid', select.classList.contains('is-invalid'));
            searchWrap.hidden = select.options.length <= 8;
            search.value = '';
        }

        select._bulkThemeSync = rebuild;

        const observer = new MutationObserver(rebuild);
        observer.observe(select, { childList: true, attributes: true, attributeFilter: ['disabled', 'class'] });
        select.addEventListener('change', rebuild);
        select.addEventListener('focus', function () {
            if (!trigger.disabled) trigger.focus();
        });

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (select.disabled) return;
            const willOpen = !dropdown.classList.contains('show');
            document.querySelectorAll('.single-select-dropdown.show').forEach(function (dd) {
                if (dd === dropdown) return;
                dd.classList.remove('show');
                const other = dd.parentElement && dd.parentElement.querySelector('.single-select-input');
                if (other) other.setAttribute('aria-expanded', 'false');
            });
            form.querySelectorAll('.multi-select-dropdown.show').forEach(function (dd) {
                dd.classList.remove('show');
            });
            dropdown.classList.toggle('show', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen && !searchWrap.hidden) search.focus();
        });

        search.addEventListener('click', function (e) {
            e.stopPropagation();
        });
        search.addEventListener('input', function () {
            const term = String(search.value || '').toLowerCase();
            options.querySelectorAll('.single-select-option').forEach(function (el) {
                const text = String(el.textContent || '').toLowerCase();
                el.classList.toggle('hidden', term !== '' && text.indexOf(term) === -1);
            });
        });
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') e.preventDefault();
        });

        dropdown.addEventListener('click', function (e) {
            const opt = e.target.closest('.single-select-option');
            if (!opt || opt.classList.contains('hidden')) return;
            e.stopPropagation();
            const next = opt.getAttribute('data-value') || '';
            if (select.value !== next) {
                select.value = next;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            dropdown.classList.remove('show');
            trigger.setAttribute('aria-expanded', 'false');
            rebuild();
        });

        rebuild();
    }

    function mountBulkThemeSelects() {
        form.querySelectorAll('.bulk-done-field select').forEach(mountBulkThemeSelect);
    }

    function refreshBulkDoneLanguages(row, preferredLanguage) {
        const countryEl = row.querySelector('[data-bulk-country]');
        const langEl = row.querySelector('[data-bulk-language]');
        if (!countryEl || !langEl) return;
        const code = String(countryEl.value || '').toLowerCase();
        const list = countryLanguageMap[code] || [];
        const keep = String(preferredLanguage || langEl.value || '').toLowerCase();
        langEl.innerHTML = '';
        if (!code) {
            langEl.disabled = true;
            langEl.innerHTML = '<option value="">Select country first</option>';
            syncBulkThemeSelect(langEl);
            return;
        }
        langEl.disabled = false;
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select…';
        langEl.appendChild(placeholder);
        list.forEach(function (rowLang) {
            const opt = document.createElement('option');
            opt.value = rowLang.code;
            opt.textContent = rowLang.name || String(rowLang.code).toUpperCase();
            if (keep && keep === String(rowLang.code).toLowerCase()) {
                opt.selected = true;
            }
            langEl.appendChild(opt);
        });
        if (list.length === 1) {
            langEl.value = list[0].code;
        }
        syncBulkThemeSelect(langEl);
    }

    form.querySelectorAll('[data-bulk-done-row]').forEach(function (row) {
        const countryEl = row.querySelector('[data-bulk-country]');
        if (!countryEl) return;
        countryEl.addEventListener('change', function () {
            refreshBulkDoneLanguages(row, '');
            if (typeof syncDoneState === 'function') syncDoneState();
        });
        // Keep server-old language when present; otherwise lock until country picked.
        refreshBulkDoneLanguages(row, row.querySelector('[data-bulk-language]')?.value || '');
    });

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
            emptyId: 'categoryEmpty-' + uid,
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
            if (!parsed || typeof parsed !== 'object') return null;
            if (!parsed.items) parsed.items = {};
            if (!Array.isArray(parsed.rejected)) parsed.rejected = [];
            if (!parsed.savedAt || (Date.now() - Number(parsed.savedAt)) > draftTtlMs) {
                sessionStorage.removeItem(draftKey);
                return null;
            }
            return parsed;
        } catch (e) {
            return null;
        }
    }

    function saveDraft(payload) {
        try {
            sessionStorage.setItem(draftKey, JSON.stringify(payload));
        } catch (e) {}
    }

    function collectItemDrafts() {
        const items = {};
        doneRows().forEach(function (row) {
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
            const draftFields = ['site_name', 'price', 'example_url', 'turnaround_time', 'publication_time', 'link_type', 'site_tag', 'description'];
            const extra = {};
            draftFields.forEach(function (field) {
                const el = row.querySelector('[name*="[' + field + ']"]');
                extra[field] = el ? el.value : '';
            });
            items[itemId] = Object.assign({
                language: language ? language.value : '',
                country: country ? country.value : '',
                da: da ? da.value : '',
                dr: dr ? dr.value : '',
                traffic: traffic ? traffic.value : '',
                categories: categories ? categories.value : '',
            }, extra);
        });
        return items;
    }

    function writeDraft() {
        const payload = {
            savedAt: Date.now(),
            items: collectItemDrafts(),
            rejected: rejectedIds(),
            rejection_note: String((noteEl && noteEl.value) || ''),
        };
        saveDraft(payload);
        const token = form.querySelector('input[name="_token"]');
        if (!draftUrl || !token) return;
        fetch(draftUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token.value,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                items: payload.items,
                rejected: payload.rejected,
                rejection_note: payload.rejection_note,
                forget_covers: Array.from(form.querySelectorAll('[data-bulk-done-row][data-forget-cover]')).map(function (row) {
                    return Number(row.getAttribute('data-forget-cover'));
                }).filter(function (id) { return id > 0; }),
            }),
        }).catch(function () {});
    }

    function clearDraft() {
        try { sessionStorage.removeItem(draftKey); } catch (e) {}
    }

    function restoreDraftIfNeeded() {
        if (hasServerOld) return;
        const draft = readDraft();
        if (!draft) return;

        (draft.rejected || []).forEach(function (id) {
            const row = form.querySelector('[data-bulk-done-row][data-item-id="' + String(id) + '"]');
            if (row && row.getAttribute('data-bulk-rejected') !== '1') {
                applyRejectedState(row);
            }
        });
        if (noteEl && Object.prototype.hasOwnProperty.call(draft, 'rejection_note')) {
            noteEl.value = String(draft.rejection_note || '');
        }

        Object.keys(draft.items || {}).forEach(function (itemId) {
            const data = draft.items[itemId] || {};
            const language = form.querySelector('select[name="items[' + itemId + '][language]"]');
            const country = form.querySelector('select[name="items[' + itemId + '][country]"]');
            const da = form.querySelector('input[name="items[' + itemId + '][da]"]');
            const dr = form.querySelector('input[name="items[' + itemId + '][dr]"]');
            const traffic = form.querySelector('input[name="items[' + itemId + '][traffic]"]');
            const row = (country && country.closest('[data-bulk-done-row]'))
                || (language && language.closest('[data-bulk-done-row]'));
            if (row && row.getAttribute('data-bulk-rejected') === '1') return;
            if (country && data.country) country.value = data.country;
            if (row) refreshBulkDoneLanguages(row, data.language || '');
            if (language && data.language) language.value = data.language;
            if (da && data.da !== undefined && data.da !== null) da.value = data.da;
            if (dr && data.dr !== undefined && data.dr !== null) dr.value = data.dr;
            if (traffic && data.traffic !== undefined && data.traffic !== null) traffic.value = data.traffic;
            ['site_name', 'price', 'example_url', 'turnaround_time', 'publication_time', 'link_type', 'site_tag', 'description'].forEach(function (field) {
                const el = form.querySelector('[name="items[' + itemId + '][' + field + ']"]');
                if (el && data[field] !== undefined && data[field] !== null) el.value = data[field];
            });

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
            if (row && rowStarted(row)) {
                row.open = true;
            }
            if (row) syncBulkThemeSelects(row);
        });
    }

    function fieldFilled(el) {
        if (el.type === 'file') {
            if (el.files && el.files.length > 0) return true;
            return el.getAttribute('data-bulk-kept') === '1'
                || !!el.closest('[data-bulk-done-row]')?.querySelector('[data-bulk-kept-image]');
        }
        const value = String(el.value ?? '').trim();
        if (value === '') return false;
        if (el.tagName === 'TEXTAREA' && el.name && el.name.indexOf('[description]') !== -1 && value.length < 50) {
            return false;
        }
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
        return Array.from(form.querySelectorAll('[data-bulk-done-row]')).filter(function (row) {
            return row.getAttribute('data-bulk-rejected') !== '1';
        });
    }

    function rejectedIds() {
        return Array.from(form.querySelectorAll('input[name="rejected_item_ids[]"]'))
            .map(function (el) { return String(el.value || '').trim(); })
            .filter(Boolean);
    }

    function noteCharCount(note) {
        // Match PHP mb_strlen (Unicode code points), not UTF-16 .length.
        return Array.from(String(note || '')).length;
    }

    function rejectionNoteOk() {
        const count = noteCharCount(String((noteEl && noteEl.value) || '').trim());
        return count >= 10 && count <= 1000;
    }

    function applyRejectedState(row) {
        const id = row.getAttribute('data-item-id');
        if (!id) return null;
        row.classList.add('d-none');
        row.setAttribute('data-bulk-rejected', '1');
        row.querySelectorAll('select, input, textarea, button').forEach(function (el) {
            el.disabled = true;
        });
        if (rejectedBox && !form.querySelector('input[name="rejected_item_ids[]"][value="' + id + '"]')) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'rejected_item_ids[]';
            hidden.value = id;
            rejectedBox.appendChild(hidden);
        }
        return id;
    }

    function markRowRejected(row) {
        if (!applyRejectedState(row)) return;
        writeDraft();
        syncDoneState();
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

    function expandBulkDoneRow(field) {
        const row = field && field.closest('[data-bulk-done-row]');
        if (row) {
            row.open = true;
        }
    }

    function refreshBulkDoneQuality(row) {
        const da = parseInt((row.querySelector('input[name*="[da]"]') || {}).value, 10);
        const dr = parseInt((row.querySelector('input[name*="[dr]"]') || {}).value, 10);
        const traffic = parseInt((row.querySelector('input[name*="[traffic]"]') || {}).value, 10);
        const filled = Number.isFinite(da) && Number.isFinite(dr) && Number.isFinite(traffic);
        const below = filled && (da < qualityMinDa || dr < qualityMinDr || traffic < qualityMinTraffic);
        const warn = row.querySelector('[data-bulk-quality-warn]');
        const chip = row.querySelector('[data-bulk-quality-chip]');
        if (warn) warn.classList.toggle('d-none', !below);
        if (chip) chip.classList.toggle('d-none', !below);
    }

    function updateBulkDoneChip(row) {
        const required = rowFields(row);
        const filled = required.filter(fieldFilled).length;
        const chip = row.querySelector('[data-bulk-done-chip]');
        if (chip) {
            chip.classList.remove('is-empty', 'is-partial', 'is-ready');
            if (filled === 0) {
                chip.classList.add('is-empty');
                chip.textContent = 'Empty';
            } else if (filled >= required.length) {
                chip.classList.add('is-ready');
                chip.textContent = 'Ready';
            } else {
                chip.classList.add('is-partial');
                chip.textContent = filled + '/' + required.length + ' filled';
            }
        }
        refreshBulkDoneQuality(row);
    }

    function clearBulkDoneRow(row) {
        rowFields(row).forEach(function (field) {
            field.value = '';
        });
        refreshBulkDoneLanguages(row, '');
        const id = rowItemId(row);
        if (id && multiSelects[id]) {
            multiSelects[id].setSelectedItems([], []);
        }
        row.querySelectorAll('input[type="checkbox"][name*="[sensitive]"]').forEach(function (el) {
            el.checked = false;
        });
        row.querySelectorAll('input[name*="[price_sensitive]"]').forEach(function (el) {
            el.value = '';
        });
        row.querySelectorAll('.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
        });
        const file = row.querySelector('input[type="file"][data-bulk-required]');
        if (file) {
            file.removeAttribute('data-bulk-kept');
            file.required = true;
        }
        row.querySelectorAll('[data-bulk-kept-image]').forEach(function (el) { el.remove(); });
        const clearedId = row.getAttribute('data-item-id');
        if (clearedId) row.dataset.forgetCover = clearedId;
        syncBulkThemeSelects(row);
        scheduleDraftSave();
        syncDoneState();
    }

    function copyBulkDoneRowFromAbove(row) {
        row.open = true;
        let prev = row.previousElementSibling;
        while (prev && !prev.hasAttribute('data-bulk-done-row')) {
            prev = prev.previousElementSibling;
        }
        if (!prev) return;

        const srcCountry = prev.querySelector('[data-bulk-country]');
        const destCountry = row.querySelector('[data-bulk-country]');
        if (srcCountry && destCountry) {
            destCountry.value = srcCountry.value;
        }
        const srcLang = prev.querySelector('[data-bulk-language]');
        refreshBulkDoneLanguages(row, (srcLang && srcLang.value) || '');
        const destLang = row.querySelector('[data-bulk-language]');
        if (srcLang && destLang && srcLang.value) {
            destLang.value = srcLang.value;
        }

        ['da', 'dr', 'traffic', 'example_url', 'turnaround_time', 'publication_time', 'link_type', 'site_tag', 'description'].forEach(function (field) {
            const src = prev.querySelector('[name*="[' + field + ']"]');
            const dest = row.querySelector('[name*="[' + field + ']"]');
            if (src && dest) dest.value = src.value;
        });
        ['crypto', 'trading', 'CBD', 'forex'].forEach(function (topic) {
            const srcCheck = prev.querySelector('input[name*="[sensitive][' + topic + ']"]');
            const destCheck = row.querySelector('input[name*="[sensitive][' + topic + ']"]');
            if (srcCheck && destCheck) destCheck.checked = srcCheck.checked;
            const srcPrice = prev.querySelector('input[name*="[price_sensitive][' + topic + ']"]');
            const destPrice = row.querySelector('input[name*="[price_sensitive][' + topic + ']"]');
            if (srcPrice && destPrice) destPrice.value = srcPrice.value;
        });
        syncBulkThemeSelects(row);

        const srcCats = prev.querySelector('input[name*="[categories]"]');
        const nicheValues = String((srcCats && srcCats.value) || '')
            .split('|')
            .map(function (v) { return v.trim(); })
            .filter(Boolean);
        const destId = rowItemId(row);
        if (destId && multiSelects[destId]) {
            multiSelects[destId].setSelectedItems(nicheValues, nicheValues);
        } else {
            const destCats = row.querySelector('input[name*="[categories]"]');
            if (destCats) destCats.value = nicheValues.join('|');
        }
        scheduleDraftSave();
        syncDoneState();
    }

    function rowIsEmpty(row) {
        return !rowStarted(row);
    }

    function applyBulkRowSharedFields(src, dest) {
        const srcCountry = src.querySelector('[data-bulk-country]');
        const destCountry = dest.querySelector('[data-bulk-country]');
        if (srcCountry && destCountry) destCountry.value = srcCountry.value;
        const srcLang = src.querySelector('[data-bulk-language]');
        refreshBulkDoneLanguages(dest, (srcLang && srcLang.value) || '');
        const destLang = dest.querySelector('[data-bulk-language]');
        if (srcLang && destLang && srcLang.value) destLang.value = srcLang.value;
        ['turnaround_time', 'publication_time', 'link_type', 'site_tag'].forEach(function (field) {
            const from = src.querySelector('[name*="[' + field + ']"]');
            const to = dest.querySelector('[name*="[' + field + ']"]');
            if (from && to) to.value = from.value;
        });
    }

    function applyOptionalGroups(src, dest) {
        const opted = function (name) {
            const box = document.querySelector('[data-bulk-apply-opt="' + name + '"]');
            return !!(box && box.checked);
        };
        if (opted('description')) {
            const from = src.querySelector('[name*="[description]"]');
            const to = dest.querySelector('[name*="[description]"]');
            if (from && to) to.value = from.value;
        }
        if (opted('sensitive')) {
            ['crypto', 'trading', 'CBD', 'forex'].forEach(function (topic) {
                const srcCheck = src.querySelector('input[name*="[sensitive][' + topic + ']"]');
                const destCheck = dest.querySelector('input[name*="[sensitive][' + topic + ']"]');
                if (srcCheck && destCheck) destCheck.checked = srcCheck.checked;
                const srcPrice = src.querySelector('input[name*="[price_sensitive][' + topic + ']"]');
                const destPrice = dest.querySelector('input[name*="[price_sensitive][' + topic + ']"]');
                if (srcPrice && destPrice) destPrice.value = srcPrice.value;
            });
        }
        if (opted('homepage')) {
            dest.querySelectorAll('[data-bulk-homepage]').forEach(function (box) {
                const match = (box.name || '').match(/\[homepage]\[([^\]]+)]/);
                if (!match) return;
                const srcBox = src.querySelector('[name*="[homepage][' + match[1] + ']"]');
                if (srcBox) box.checked = srcBox.checked;
                const srcPrice = src.querySelector('[data-bulk-homepage-price="' + match[1] + '"]');
                const destPrice = dest.querySelector('[data-bulk-homepage-price="' + match[1] + '"]');
                if (srcPrice && destPrice) destPrice.value = srcPrice.value;
            });
        }
        if (opted('social')) {
            dest.querySelectorAll('[data-bulk-social]').forEach(function (box) {
                const channel = box.getAttribute('data-bulk-social');
                const srcBox = src.querySelector('[data-bulk-social="' + channel + '"]');
                if (srcBox) box.checked = srcBox.checked;
            });
        }
        if (opted('niches')) {
            const srcCats = src.querySelector('input[name*="[categories]"]');
            const nicheValues = String((srcCats && srcCats.value) || '').split('|').map(function (v) { return v.trim(); }).filter(Boolean);
            const destId = rowItemId(dest);
            if (destId && multiSelects[destId]) {
                multiSelects[destId].setSelectedItems(nicheValues, nicheValues);
            }
        }
    }

    function applyBulkDoneRowToEmpty(row) {
        doneRows().forEach(function (dest) {
            if (dest === row || !rowIsEmpty(dest)) return;
            dest.open = true;
            applyBulkRowSharedFields(row, dest);
            applyOptionalGroups(row, dest);
            syncBulkThemeSelects(dest);
        });
        scheduleDraftSave();
        syncDoneState();
    }

    function rowChipState(row) {
        const chip = row.querySelector('[data-bulk-done-chip]');
        if (!chip) return 'empty';
        if (chip.classList.contains('is-ready')) return 'ready';
        if (chip.classList.contains('is-partial')) return 'incomplete';
        return 'empty';
    }

    function applyBulkRowFilter() {
        const query = String((document.getElementById('bulkRowFilter') || {}).value || '').trim().toLowerCase();
        const active = document.querySelector('[data-bulk-chip-filter].active');
        const mode = active ? active.getAttribute('data-bulk-chip-filter') : 'all';
        form.querySelectorAll('[data-bulk-done-row]').forEach(function (row) {
            if (row.getAttribute('data-bulk-rejected') === '1') return;
            const hay = (String(row.getAttribute('data-domain') || '') + ' ' + String(row.getAttribute('data-url') || '')).toLowerCase();
            const textOk = query === '' || hay.indexOf(query) !== -1;
            const state = rowChipState(row);
            const below = row.querySelector('[data-bulk-quality-chip]') && !row.querySelector('[data-bulk-quality-chip]').classList.contains('d-none');
            let chipOk = mode === 'all' || state === mode;
            if (mode === 'below') chipOk = !!below;
            row.classList.toggle('is-filtered', !(textOk && chipOk));
        });
    }

    function setIncompleteRowsDisabled(disabled) {
        doneRows().forEach(function (row) {
            if (rowFilled(row)) return;
            row.querySelectorAll('select, input, textarea, button').forEach(function (el) {
                if (el.closest('.bulk-theme-select') && el.tagName === 'BUTTON') return;
                if (!disabled && el.hasAttribute('data-bulk-language')) {
                    const country = row.querySelector('[data-bulk-country]');
                    el.disabled = !country || String(country.value || '').trim() === '';
                    return;
                }
                el.disabled = !!disabled;
            });
            syncBulkThemeSelects(row);
        });
    }

    function pruneDraftForItemIds(itemIds) {
        writeDraft();
        const draft = readDraft();
        if (!draft) return;
        const drop = {};
        (itemIds || []).forEach(function (id) {
            drop[String(id)] = true;
            delete draft.items[String(id)];
        });
        draft.rejected = (draft.rejected || []).filter(function (id) {
            return !drop[String(id)];
        });
        if (Object.keys(draft.items).length === 0
            && draft.rejected.length === 0
            && !String(draft.rejection_note || '').trim()) {
            clearDraft();
            return;
        }
        draft.savedAt = Date.now();
        saveDraft(draft);
    }

    function doneFormReady() {
        const complete = completeRows();
        const partial = partialRows();
        const rejected = rejectedIds();
        const noteOk = rejectionNoteOk();
        const hasWork = complete.length > 0 || rejected.length > 0;
        const noteReady = rejected.length === 0 || noteOk;
        return {
            complete: complete,
            partial: partial,
            rejected: rejected,
            noteOk: noteOk,
            ready: partial.length === 0 && hasWork && noteReady,
        };
    }

    function syncDoneState() {
        doneRows().forEach(updateBulkDoneChip);
        const open = submitBtn && submitBtn.getAttribute('data-open') === '1';
        const state = doneFormReady();
        const complete = state.complete;
        const partial = state.partial;
        const rejected = state.rejected;
        const noteOk = state.noteOk;
        const ready = state.ready;
        if (noteWrap) {
            noteWrap.classList.toggle('d-none', rejected.length === 0);
        }
        if (submitBtn) {
            submitBtn.disabled = !(open && ready);
            if (complete.length > 0) {
                submitBtn.textContent = complete.length === 1
                    ? 'Publish 1 site now'
                    : ('Publish ' + complete.length + ' sites now');
            } else if (rejected.length > 0) {
                submitBtn.textContent = rejected.length === 1
                    ? 'Remove 1 site & notify publisher'
                    : ('Remove ' + rejected.length + ' sites & notify publisher');
            } else {
                submitBtn.textContent = 'Publish filled sites now';
            }
        }
        if (reviewBtn) {
            const canReview = open && ready && complete.length > 0;
            reviewBtn.disabled = !canReview;
            reviewBtn.textContent = complete.length === 1
                ? 'Send 1 site to publisher for review'
                : (complete.length > 1
                    ? ('Send ' + complete.length + ' sites to publisher for review')
                    : 'Send filled sites to publisher for review');
        }
        if (hint) {
            hint.classList.toggle('d-none', ready);
            if (partial.length > 0) {
                hint.textContent = 'Finish or clear incomplete rows first. You can submit the '
                    + complete.length + ' complete block(s) after that.';
            } else if (rejected.length > 0 && !noteOk) {
                hint.textContent = 'Add a note for the publisher about the removed sites (10–1000 characters).';
            } else if (complete.length === 0) {
                hint.textContent = 'Fill at least one complete block (country, language, DA, DR, traffic, niches, sample article, turnaround, publication, link type, listing tag, description of at least 50 characters, and a site image) before Done.';
            } else {
                hint.textContent = '';
            }
        }
        applyBulkRowFilter();
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

    form.querySelectorAll('[data-bulk-done-row][data-bulk-rejected="1"]').forEach(function (row) {
        row.querySelectorAll('select, input, textarea, button').forEach(function (el) {
            el.disabled = true;
        });
    });
    form.querySelectorAll('[data-bulk-clear-row]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = btn.closest('[data-bulk-done-row]');
            if (row && row.getAttribute('data-bulk-rejected') !== '1') {
                clearBulkDoneRow(row);
            }
        });
    });
    form.querySelectorAll('[data-bulk-copy-above]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = btn.closest('[data-bulk-done-row]');
            if (row && row.getAttribute('data-bulk-rejected') !== '1') {
                copyBulkDoneRowFromAbove(row);
            }
        });
    });
    form.querySelectorAll('[data-bulk-apply-empty]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = btn.closest('[data-bulk-done-row]');
            if (row && row.getAttribute('data-bulk-rejected') !== '1') {
                applyBulkDoneRowToEmpty(row);
            }
        });
    });
    const rowFilter = document.getElementById('bulkRowFilter');
    if (rowFilter) {
        rowFilter.addEventListener('input', applyBulkRowFilter);
    }
    document.querySelectorAll('[data-bulk-chip-filter]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-bulk-chip-filter]').forEach(function (other) {
                other.classList.remove('active');
            });
            btn.classList.add('active');
            applyBulkRowFilter();
        });
    });
    const expandAll = document.querySelector('[data-bulk-expand-all]');
    const collapseAll = document.querySelector('[data-bulk-collapse-all]');
    if (expandAll) {
        expandAll.addEventListener('click', function () {
            doneRows().forEach(function (row) {
                if (!row.classList.contains('is-filtered')) row.open = true;
            });
        });
    }
    if (collapseAll) {
        collapseAll.addEventListener('click', function () {
            doneRows().forEach(function (row) { row.open = false; });
        });
    }
    form.querySelectorAll('[data-bulk-reject-row]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = btn.closest('[data-bulk-done-row]');
            if (!row || row.getAttribute('data-bulk-rejected') === '1') return;
            const go = window.slbConfirm({
                title: 'Remove this site?',
                text: 'It will be deleted when you click Done, and the publisher will get your note.',
                confirmText: 'Remove row',
                danger: true,
            });
            go.then(function (ok) {
                if (ok) markRowRejected(row);
            });
        });
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

    form.addEventListener('click', function (e) {
        const btn = e.target && e.target.closest ? e.target.closest('button[type="submit"]') : null;
        if (btn && btn.form === form && !btn.disabled) {
            form._slbDoneSubmitter = btn;
        }
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

        const state = doneFormReady();
        const complete = state.complete;
        const partial = state.partial;
        const rejected = state.rejected;
        const noteOk = state.noteOk;
        const ready = state.ready;
        if (!ready) {
            e.preventDefault();
            syncDoneState();
            if (partial.length > 0) {
                const firstPartial = rowFields(partial[0]).find((el) => !fieldFilled(el));
                if (firstPartial) {
                    expandBulkDoneRow(firstPartial);
                    firstPartial.focus();
                    firstPartial.classList.add('is-invalid');
                }
                slbAlert({
                    icon: 'warning',
                    title: 'Finish incomplete blocks',
                    text: 'Each started row must be fully filled, or clear it. Then submit the complete block(s). Empty rows can wait for later.',
                });
            } else if (rejected.length > 0 && !noteOk) {
                if (noteEl) {
                    noteEl.focus();
                    noteEl.classList.add('is-invalid');
                }
                slbAlert({
                    icon: 'warning',
                    title: 'Add a publisher note',
                    text: 'Write one note (10–1000 characters) for the sites you are removing. The publisher receives that single note.',
                });
            } else {
                const firstEmpty = fields().find((el) => !fieldFilled(el));
                if (firstEmpty) {
                    expandBulkDoneRow(firstEmpty);
                    firstEmpty.focus();
                    firstEmpty.classList.add('is-invalid');
                }
                slbAlert({
                    icon: 'warning',
                    title: 'Fill at least one block',
                    text: 'Fill every required field, including the site image, on at least one website, then choose Publish now or Send for review. Other rows can stay empty for later.',
                });
            }
            return false;
        }

        const count = complete.length;
        const remaining = doneRows().length - count;
        const submittedIds = complete.map(rowItemId).filter(Boolean).concat(rejected);
        const clicked = form._slbDoneSubmitter;
        const chosenSubmitter = (e.submitter && e.submitter.form === form)
            ? e.submitter
            : (clicked && clicked.form === form ? clicked : submitBtn);
        form._slbDoneSubmitter = chosenSubmitter;
        const reviewMode = !!(chosenSubmitter && chosenSubmitter.id === 'bulkReviewSubmit');
        e.preventDefault();
        let confirmTitle = reviewMode ? 'Send these sites for review?' : 'Publish these sites?';
        let confirmText = reviewMode
            ? (remaining > 0
                ? ('Send ' + count + ' complete site(s) to the publisher for review? They stay off the catalog until the publisher submits them. ' + remaining + ' unfinished row(s) will stay pending.')
                : ('Send ' + count + ' site(s) to the publisher for review? They stay off the catalog until the publisher submits them.'))
            : (remaining > 0
                ? ('Publish ' + count + ' complete site(s) now and notify the publisher? They go live and stay unverified. ' + remaining + ' unfinished row(s) will stay pending.')
                : ('Publish ' + count + ' site(s) on the publisher’s account and notify them? They go live and stay unverified.'));
        let confirmTextBtn = reviewMode ? 'Send for review' : 'Publish sites';
        if (count > 0 && rejected.length > 0) {
            confirmTitle = reviewMode ? 'Send for review and remove others?' : 'Publish sites and remove others?';
            confirmText = (reviewMode ? 'Send ' : 'Publish ') + count + ' site(s) and remove ' + rejected.length
                + ' site(s)? The publisher gets both notices.'
                + (remaining > 0 ? (' ' + remaining + ' unfinished row(s) will stay pending.') : '');
            confirmTextBtn = reviewMode ? 'Send for review' : 'Publish sites';
        } else if (count === 0 && rejected.length > 0) {
            confirmTitle = 'Remove sites?';
            confirmText = 'Remove ' + rejected.length + ' site(s) and notify the publisher with your note?'
                + (remaining > 0 ? (' ' + remaining + ' unfinished row(s) will stay pending.') : '');
            confirmTextBtn = 'Remove sites';
        }
        const confirmFn = window.slbConfirm({
            title: confirmTitle,
            text: confirmText,
            confirmText: confirmTextBtn,
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
            const chosen = form._slbDoneSubmitter || submitBtn;
            if (typeof form.requestSubmit === 'function' && chosen && !chosen.disabled) {
                const staleMode = form.querySelector('input[data-bulk-done-mode]');
                if (staleMode) {
                    staleMode.remove();
                }
                try {
                    form.requestSubmit(chosen);
                    return;
                } catch (err) {
                    // A browser that rejects the submitter falls through and stamps done_mode.
                }
            }
            let modeInput = form.querySelector('input[data-bulk-done-mode]');
            if (!modeInput) {
                modeInput = document.createElement('input');
                modeInput.type = 'hidden';
                modeInput.name = 'done_mode';
                modeInput.setAttribute('data-bulk-done-mode', '1');
                form.appendChild(modeInput);
            }
            modeInput.value = chosen && chosen.id === 'bulkReviewSubmit' ? 'review' : 'publish';
            HTMLFormElement.prototype.submit.call(form);
        });
    });

    function focusFirstInvalidDoneField() {
        const invalids = Array.from(form.querySelectorAll('.is-invalid'));
        if (!invalids.length) return;
        const focusable = invalids.find(function (el) {
            return el.type !== 'hidden' && typeof el.focus === 'function';
        });
        const target = focusable || invalids[0];
        expandBulkDoneRow(target);
        if (target.type === 'hidden') {
            const row = target.closest('[data-bulk-done-row]');
            const ms = row && row.querySelector('.multi-select-input');
            if (ms && typeof ms.focus === 'function') {
                ms.focus();
                return;
            }
        }
        if (typeof target.focus === 'function') {
            target.focus();
        }
    }

    mountBulkThemeSelects();
    syncDoneState();
    focusFirstInvalidDoneField();
})();

document.querySelectorAll('form.bulk-request-cancel').forEach(function (form) {
    form.addEventListener('submit', async function (e) {
        if (form.dataset.slbCancelAllow === '1') {
            delete form.dataset.slbCancelAllow;
            return;
        }
        e.preventDefault();
        const promptText = 'Cancel this bulk request? Pending drafts are removed. History is kept. Explain why — the publisher will see this reason.';
        let reason = '';
        if (window.Swal && typeof window.Swal.fire === 'function') {
            const result = await window.Swal.fire({
                title: 'Cancel bulk request?',
                text: promptText,
                icon: 'warning',
                input: 'textarea',
                inputLabel: 'Reason for the publisher',
                inputPlaceholder: 'Reason (min. 10 characters)',
                inputAttributes: { 'aria-label': 'Cancel reason', maxlength: '1000' },
                showCancelButton: true,
                confirmButtonText: 'Cancel request',
                customClass: { confirmButton: 'slb-swal-danger' },
                preConfirm: function (value) {
                    const next = String(value || '').trim();
                    if (next.length < 10) {
                        window.Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                        return false;
                    }
                    if (next.length > 1000) {
                        window.Swal.showValidationMessage('Reason must be 1000 characters or fewer.');
                        return false;
                    }
                    return next;
                },
            });
            if (!result.isConfirmed) {
                return;
            }
            reason = String(result.value || '').trim();
        } else {
            const typed = window.prompt(promptText + '\n\nReason (min. 10 characters):');
            if (typed === null) {
                return;
            }
            reason = String(typed || '').trim();
            if (reason.length < 10 || reason.length > 1000) {
                await slbAlert({ icon: 'error', title: 'Please enter a reason (10–1000 characters).' });
                return;
            }
        }
        const reasonInput = form.querySelector('input[name="reason"]');
        if (reasonInput) {
            reasonInput.value = reason;
        }
        form.dataset.slbCancelAllow = '1';
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            HTMLFormElement.prototype.submit.call(form);
        }
    });
});

document.querySelectorAll('.bulk-draft-delete').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        const id = this.getAttribute('data-site-id');
        const name = this.getAttribute('data-site-name') || 'this site';
        const promptText = 'Delete draft "' + name + '"? This removes the wrong seed. Explain why — the publisher will see this reason.';

        let reason = '';
        if (window.Swal && typeof window.Swal.fire === 'function') {
            const result = await window.Swal.fire({
                title: 'Delete draft site?',
                text: promptText,
                icon: 'warning',
                input: 'textarea',
                inputLabel: 'Reason for the publisher',
                inputPlaceholder: 'Reason (min. 10 characters)',
                inputAttributes: { 'aria-label': 'Rejection reason', maxlength: '1000' },
                showCancelButton: true,
                confirmButtonText: 'Delete draft',
                customClass: { confirmButton: 'slb-swal-danger' },
                preConfirm: function (value) {
                    const next = String(value || '').trim();
                    if (next.length < 10) {
                        window.Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                        return false;
                    }
                    if (next.length > 1000) {
                        window.Swal.showValidationMessage('Reason must be 1000 characters or fewer.');
                        return false;
                    }
                    return next;
                },
            });
            if (!result.isConfirmed) {
                return;
            }
            reason = String(result.value || '').trim();
        } else {
            const typed = window.prompt(promptText + '\n\nReason (min. 10 characters):');
            if (typed === null) {
                return;
            }
            reason = String(typed || '').trim();
            if (reason.length < 10 || reason.length > 1000) {
                await slbAlert({ icon: 'error', title: 'Please enter a reason (10–1000 characters).' });
                return;
            }
        }

        this.disabled = true;
        try {
            const res = await fetch(@json(staff_base_path() . '/sites') + '/' + id, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': @json(csrf_token()),
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ reason }),
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok || !data.success) {
                const reasonErr = data.errors && data.errors.reason
                    ? (Array.isArray(data.errors.reason) ? data.errors.reason[0] : data.errors.reason)
                    : null;
                await slbAlert({ icon: 'error', title: reasonErr || data.message || 'Could not delete site.' });
                this.disabled = false;
                return;
            }
            location.reload();
        } catch (e) {
            await slbAlert({ icon: 'error', title: 'Could not delete site.' });
            this.disabled = false;
        }
    });
});
</script>
@endsection
