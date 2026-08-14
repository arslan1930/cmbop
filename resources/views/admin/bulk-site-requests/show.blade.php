@extends(staff_layout())

@section('content')
<div class="container-fluid">
    <div class="mb-3">
        <a href="{{ staff_route('bulk-site-requests.index') }}" class="small text-muted text-decoration-none">
            ← Bulk requests
        </a>
        <h3 class="mt-2 mb-1">Bulk request #{{ $bulkRequest->id }}</h3>
        <p class="text-muted small mb-1">
            Publisher: <strong>{{ $bulkRequest->publisher?->name ?? '—' }}</strong>
            ({{ $bulkRequest->publisher?->email ?? '—' }})
            · Status: <strong>{{ $bulkRequest->statusLabel() }}</strong>
            · Sites submitted: {{ $bulkRequest->items->count() ?: ($bulkRequest->estimated_count ?? '—') }}
        </p>
        <p class="small mb-0" data-bulk-progress>
            Added {{ $bulkRequest->addedItemsCount() }}
            · Rejected {{ $bulkRequest->rejectedItemsCount() }}
            · Still to Done {{ $pendingItems->count() }}
            · Publisher filling {{ $bulkRequest->pendingPublisherCount() }}
            · Ready {{ $bulkRequest->readyForReviewCount() }}
        </p>
    </div>
    @php
        $reasonError = $errors->first('reason');
        $isCancelReasonError = is_string($reasonError) && str_contains(strtolower($reasonError), 'cancell');
        $rejectItemId = (int) old('reject_item_id');
        $isRejectReasonError = $errors->has('reason') && ! $isCancelReasonError && $pendingItems->isNotEmpty();
        if ($isRejectReasonError && $rejectItemId === 0 && $pendingItems->count() === 1) {
            $rejectItemId = (int) $pendingItems->first()->id;
        }
    @endphp

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

                    @if($bulkRequest->canMarkSheetSent())
                        <form method="POST" action="{{ staff_route('bulk-site-requests.sheet-sent', $bulkRequest) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                Mark sheet emailed (optional)
                            </button>
                        </form>
                    @endif
                    @if($bulkRequest->isCancelled() && $bulkRequest->cancel_reason)
                        <div class="alert alert-secondary py-2 small mb-3" data-bulk-cancel-reason>
                            Cancelled. Reason: {{ $bulkRequest->cancel_reason }}
                        </div>
                    @endif
                    @if($bulkRequest->canAddDraftSites())
                        <form method="POST" action="{{ staff_route('bulk-site-requests.cancel', $bulkRequest) }}"
                              data-slb-confirm="Cancel this bulk request? The publisher will see your reason. History is kept."
                              data-slb-confirm-title="Cancel bulk request?"
                              data-slb-confirm-text="Cancel request"
                              data-slb-confirm-danger="1">
                            @csrf
                            <label class="form-label small" for="bulk-cancel-reason">Reason for publisher</label>
                            <textarea id="bulk-cancel-reason"
                                      name="reason"
                                      class="form-control form-control-sm mb-2 {{ $isCancelReasonError ? 'is-invalid' : '' }}"
                                      rows="2"
                                      required
                                      minlength="3"
                                      maxlength="500"
                                      placeholder="Why this request is being cancelled">{{ $isCancelReasonError ? old_text('reason') : '' }}</textarea>
                            @if($isCancelReasonError)
                                <div class="invalid-feedback d-block mb-2">{{ $reasonError }}</div>
                            @endif
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
                        Review each website, then fill <strong>Language, Country, DA, DR, Traffic, and Niches</strong> on the Done cards below.
                        Reject a site from those cards — the note goes to the publisher. Rejected rows stay listed here.
                        Sites are added to the publisher’s Pending sites as drafts — still inactive until they finish details and you verify.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Website URL</th>
                                    <th>Price</th>
                                    <th>Domain</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($bulkRequest->items as $item)
                                    <tr id="bulk-item-{{ $item->id }}">
                                        <td>
                                            <a href="{{ $item->site_url }}" target="_blank" rel="noopener noreferrer">
                                                {{ $item->site_url }}
                                            </a>
                                        </td>
                                        <td>€{{ number_format((float) $item->price, 2) }}</td>
                                        <td class="small text-muted">{{ $item->domain }}</td>
                                        <td>
                                            @if($item->site_id)
                                                <span class="badge text-bg-success">Added</span>
                                            @elseif($item->isRejected())
                                                <span class="badge text-bg-danger">Rejected</span>
                                                @if($item->reject_reason)
                                                    <div class="small text-muted mt-1">{{ $item->reject_reason }}</div>
                                                @endif
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
                        Reject a site you will not add; the rest of the batch stays open.
                    </p>

                    @if($isRejectReasonError)
                        <div class="alert alert-danger py-2 small" data-bulk-reject-error>
                            <strong>Add a note for the publisher.</strong>
                            {{ $reasonError }}
                        </div>
                    @elseif($errors->any() && ! $errors->has('rows') && ! $errors->has('reason'))
                        <div class="alert alert-danger py-2 small">
                            <strong>Finish the boxes first.</strong>
                            {{ $errors->first() }}
                        </div>
                    @endif

                    @if($bulkRequest->items->isEmpty())
                        <div class="form-text">
                            Legacy request — no URL + price rows.
                            @if($bulkRequest->canAddDraftSites())
                                Use Advanced Seed below if you still need to add drafts.
                            @endif
                        </div>
                    @elseif($pendingItems->isEmpty())
                        <div class="form-text">All submitted rows are already added or rejected.</div>
                    @elseif(! $bulkRequest->canAddDraftSites())
                        <div class="form-text" data-bulk-done-closed>
                            This request is cancelled. Remaining URL + price rows were not added.
                        </div>
                    @else
                        <form method="POST"
                              action="{{ staff_route('bulk-site-requests.done', $bulkRequest) }}"
                              id="bulkDoneForm"
                              enctype="multipart/form-data"
                              novalidate>
                            @csrf
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                <p class="small text-muted mb-0">
                                    Fill Country, Language, DA, DR, Traffic, and Niches for each website you will add.
                                </p>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Done form layout" data-bulk-done-density>
                                    <button type="button"
                                            class="btn btn-outline-secondary active"
                                            data-bulk-done-density-btn="comfortable"
                                            aria-pressed="true">
                                        Comfortable
                                    </button>
                                    <button type="button"
                                            class="btn btn-outline-secondary"
                                            data-bulk-done-density-btn="compact"
                                            aria-pressed="false">
                                        Compact
                                    </button>
                                </div>
                            </div>

                            <div class="bulk-done-list is-comfortable mb-3" data-bulk-done-list>
                                @foreach($pendingItems as $item)
                                    @php
                                        $old = old('items.'.$item->id, []);
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
                                    @endphp
                                    <article class="bulk-done-card" data-bulk-done-row data-item-id="{{ $item->id }}">
                                        <header class="bulk-done-card-head">
                                            <div class="min-w-0">
                                                <div class="fw-semibold text-break">{{ $item->domain }}</div>
                                                <a class="small text-muted text-break" href="{{ $item->site_url }}" target="_blank" rel="noopener noreferrer">
                                                    {{ $item->site_url }}
                                                </a>
                                            </div>
                                            <div class="bulk-done-card-head-meta">
                                                <div class="bulk-done-card-price text-nowrap">€{{ number_format((float) $item->price, 2) }}</div>
                                                <button type="button"
                                                        class="btn btn-link btn-sm p-0"
                                                        data-bulk-done-clear
                                                        aria-label="Clear boxes for {{ $item->domain }}">
                                                    Clear
                                                </button>
                                            </div>
                                        </header>

                                        <div class="bulk-done-card-fields">
                                            <div class="bulk-done-field">
                                                <label class="form-label" for="done-country-{{ $item->id }}">
                                                    Country <span class="text-danger">*</span>
                                                </label>
                                                <select id="done-country-{{ $item->id }}"
                                                        name="items[{{ $item->id }}][country]"
                                                        class="form-select @error('items.'.$item->id.'.country') is-invalid @enderror"
                                                        required
                                                        data-bulk-required
                                                        data-bulk-country>
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
                                                <label class="form-label" for="done-language-{{ $item->id }}">
                                                    Language <span class="text-danger">*</span>
                                                </label>
                                                <select id="done-language-{{ $item->id }}"
                                                        name="items[{{ $item->id }}][language]"
                                                        class="form-select @error('items.'.$item->id.'.language') is-invalid @enderror"
                                                        required
                                                        data-bulk-required
                                                        data-bulk-language
                                                        @disabled($oldCountry === '')>
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
                                                <label class="form-label" for="done-da-{{ $item->id }}">
                                                    DA <span class="text-danger">*</span>
                                                </label>
                                                <input type="number"
                                                       id="done-da-{{ $item->id }}"
                                                       name="items[{{ $item->id }}][da]"
                                                       class="form-control @error('items.'.$item->id.'.da') is-invalid @enderror"
                                                       placeholder="0–100"
                                                       min="0" max="100" step="1"
                                                       value="{{ $old['da'] ?? '' }}"
                                                       required
                                                       data-bulk-required
                                                       data-score-clamp="100">
                                                @error('items.'.$item->id.'.da')
                                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                                @enderror
                                            </div>

                                            <div class="bulk-done-field">
                                                <label class="form-label" for="done-dr-{{ $item->id }}">
                                                    DR <span class="text-danger">*</span>
                                                </label>
                                                <input type="number"
                                                       id="done-dr-{{ $item->id }}"
                                                       name="items[{{ $item->id }}][dr]"
                                                       class="form-control @error('items.'.$item->id.'.dr') is-invalid @enderror"
                                                       placeholder="0–100"
                                                       min="0" max="100" step="1"
                                                       value="{{ $old['dr'] ?? '' }}"
                                                       required
                                                       data-bulk-required
                                                       data-score-clamp="100">
                                                @error('items.'.$item->id.'.dr')
                                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                                @enderror
                                            </div>

                                            <div class="bulk-done-field">
                                                <label class="form-label" for="done-traffic-{{ $item->id }}">
                                                    Traffic <span class="text-danger">*</span>
                                                </label>
                                                {{-- Traffic is monthly visitors (can be millions/billions). Never clamp like DA/DR. --}}
                                                <input type="number"
                                                       id="done-traffic-{{ $item->id }}"
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
                                                       data-traffic-input>
                                                @error('items.'.$item->id.'.traffic')
                                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                                @enderror
                                            </div>

                                            <div class="bulk-done-field bulk-done-niches-cell">
                                                <div class="form-label" id="done-niches-label-{{ $uid }}">
                                                    Niches <span class="text-danger">*</span>
                                                </div>
                                                <input type="hidden"
                                                       name="items[{{ $item->id }}][categories]"
                                                       id="selectedCategories-{{ $uid }}"
                                                       value="{{ $oldCategories }}"
                                                       data-bulk-required
                                                       class="@error('items.'.$item->id.'.categories') is-invalid @enderror">
                                                <div class="multi-select-wrapper" id="categoryWrapper-{{ $uid }}" data-multi-select="category">
                                                    <div class="multi-select-input @error('items.'.$item->id.'.categories') is-invalid @enderror"
                                                         id="categoryInput-{{ $uid }}"
                                                         role="button"
                                                         tabindex="0"
                                                         aria-haspopup="listbox"
                                                         aria-expanded="false"
                                                         aria-labelledby="done-niches-label-{{ $uid }}"
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
                                        </div>

                                        @if($bulkRequest->status !== \App\Models\BulkSiteRequest::STATUS_CANCELLED)
                                            <footer class="bulk-done-card-reject">
                                                <label class="form-label" for="reject-note-{{ $item->id }}">Note for publisher</label>
                                                <div class="bulk-done-card-reject-row">
                                                    <textarea id="reject-note-{{ $item->id }}"
                                                              form="reject-item-{{ $item->id }}"
                                                              name="reason"
                                                              class="form-control {{ $isRejectReasonError && $rejectItemId === (int) $item->id ? 'is-invalid' : '' }}"
                                                              required
                                                              minlength="3"
                                                              maxlength="500"
                                                              rows="3"
                                                              placeholder="Why this site will not be added"
                                                              data-bulk-reject-note
                                                              aria-label="Note for publisher about {{ $item->domain }}">{{ $isRejectReasonError && $rejectItemId === (int) $item->id ? old_text('reason') : '' }}</textarea>
                                                    <button type="submit"
                                                            class="btn btn-outline-danger"
                                                            form="reject-item-{{ $item->id }}"
                                                            data-bulk-reject-submit
                                                            data-slb-confirm="Reject this site only. The rest of the batch stays open."
                                                            data-slb-confirm-title="Reject this website?"
                                                            data-slb-confirm-text="Reject site"
                                                            data-slb-confirm-danger="1">
                                                        Reject
                                                    </button>
                                                </div>
                                                @if($isRejectReasonError && $rejectItemId === (int) $item->id)
                                                    <div class="invalid-feedback d-block">{{ $reasonError }}</div>
                                                @endif
                                            </footer>
                                        @endif
                                    </article>
                                @endforeach
                            </div>

                            <div id="bulkDoneHint" class="alert alert-warning py-2 small mb-3" role="status">
                                Fill at least one complete block (Country, Language, DA, DR, Traffic, Niches) before Done.
                            </div>

                            <button type="submit"
                                    id="bulkDoneSubmit"
                                    class="btn btn-primary"
                                    data-open="{{ $bulkRequest->canAddDraftSites() ? '1' : '0' }}"
                                    disabled>
                                Done — add filled sites &amp; notify publisher
                            </button>
                        </form>
                        @foreach($pendingItems as $item)
                            <form id="reject-item-{{ $item->id }}"
                                  method="POST"
                                  action="{{ staff_route('bulk-site-requests.items.reject', [$bulkRequest->id, $item->id]) }}"
                                  class="d-none"
                                  data-slb-confirm="Reject this site only. The rest of the batch stays open."
                                  data-slb-confirm-title="Reject this website?"
                                  data-slb-confirm-text="Reject site"
                                  data-slb-confirm-danger="1">
                                @csrf
                                <input type="hidden" name="reject_item_id" value="{{ $item->id }}">
                            </form>
                        @endforeach
                    @endif
                </div>
            </div>

            @if($bulkRequest->items->isEmpty() && $bulkRequest->canAddDraftSites())
            <div class="card border-0 shadow-sm mb-3" data-bulk-advanced-seed>
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Advanced: seed with per-row metrics</h6>
                    <p class="small text-muted mb-3">
                        Legacy requests only (no URL + price list). Paste one site per line.
                        Columns: <code>url,price,da,dr,traffic,country,language[,site_name]</code>
                    </p>
                    <form method="POST"
                          action="{{ staff_route('bulk-site-requests.seed', $bulkRequest) }}"
                          data-slb-confirm="Seed these pasted rows as drafts and notify the publisher?"
                          data-slb-confirm-title="Seed draft sites?"
                          data-slb-confirm-text="Seed">
                        @csrf
                        <textarea name="rows" id="bulkSeedRows" class="form-control font-monospace small @error('rows') is-invalid @enderror" rows="8"
                                  placeholder="https://example.com,99,40,45,12000,de,de,Example Blog">{{ old_text('rows') }}</textarea>
                        @error('rows')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-outline-primary btn-sm mt-2">
                            Seed from pasted rows &amp; notify publisher
                        </button>
                    </form>
                </div>
            </div>
            @endif

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
<script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="{{ asset('js/multi-select.js') }}?v={{ @filemtime(public_path('js/multi-select.js')) ?: '1' }}"></script>
<script>
(function () {
    const list = document.querySelector('[data-bulk-done-list]');
    const buttons = document.querySelectorAll('[data-bulk-done-density-btn]');
    if (!list || !buttons.length) return;

    const storageKey = 'bulkDoneDensity';

    function readStoredDensity() {
        // Same-tab sessionStorage wins. A stale localStorage "comfortable"
        // from an earlier apply must not wipe a Compact click in this tab.
        const stores = [];
        try { stores.push(sessionStorage.getItem(storageKey)); } catch (e) {}
        try { stores.push(localStorage.getItem(storageKey)); } catch (e) {}
        for (let i = 0; i < stores.length; i++) {
            if (stores[i] === 'compact' || stores[i] === 'comfortable') return stores[i];
        }
        return 'comfortable';
    }

    function writeStoredDensity(mode) {
        try { sessionStorage.setItem(storageKey, mode); } catch (e) {}
        try { localStorage.setItem(storageKey, mode); } catch (e) {}
    }

    function applyDensity(mode, persist) {
        const next = mode === 'compact' ? 'compact' : 'comfortable';
        list.classList.toggle('is-compact', next === 'compact');
        list.classList.toggle('is-comfortable', next === 'comfortable');
        buttons.forEach(function (btn) {
            const on = btn.getAttribute('data-bulk-done-density-btn') === next;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (persist) writeStoredDensity(next);
    }

    applyDensity(readStoredDensity(), false);

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            applyDensity(btn.getAttribute('data-bulk-done-density-btn'), true);
        });
    });
})();

(function () {
    const form = document.getElementById('bulkDoneForm');
    if (!form) return;

    const submitBtn = document.getElementById('bulkDoneSubmit');
    const hint = document.getElementById('bulkDoneHint');
    const fields = () => Array.from(form.querySelectorAll('[data-bulk-required]'));
    const multiSelects = {};
    const prefills = {};
    const serverOldItemIds = @json(array_map('strval', array_keys(is_array(old('items')) ? old('items') : [])));
    const draftKey = @json('bulkDoneDraft:'.$bulkRequest->id.':'.auth()->id());
    const draftTtlMs = 24 * 60 * 60 * 1000;
    const countryLanguageMap = @json($countryLanguageMap ?? new \stdClass());

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

    function safeItemId(itemId) {
        const id = String(itemId == null ? '' : itemId);
        return /^\d+$/.test(id) ? id : null;
    }

    Object.keys(prefills).forEach(function (itemId) {
        try {
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
        } catch (e) {}
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
            const rejectNote = row.querySelector('[data-bulk-reject-note]');
            const itemId = rowItemId(row);
            if (!itemId) return;
            items[itemId] = {
                language: language ? language.value : '',
                country: country ? country.value : '',
                da: da ? da.value : '',
                dr: dr ? dr.value : '',
                traffic: traffic ? traffic.value : '',
                categories: categories ? categories.value : '',
                reject_note: rejectNote ? String(rejectNote.value || '') : '',
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

    function restoreRejectNote(itemId, data, row) {
        const note = (row && row.querySelector('[data-bulk-reject-note]'))
            || form.querySelector('#reject-note-' + itemId);
        if (note && !String(note.value || '').trim() && data.reject_note) {
            note.value = String(data.reject_note);
        }
    }

    function restoreDraftIfNeeded() {
        try {
            const draft = readDraft();
            if (!draft || !draft.items) return;

            Object.keys(draft.items).forEach(function (rawId) {
                const itemId = safeItemId(rawId);
                if (!itemId) return;
                try {
                    const data = draft.items[rawId] || {};
                    const language = form.querySelector('select[name="items[' + itemId + '][language]"]');
                    const country = form.querySelector('select[name="items[' + itemId + '][country]"]');
                    const da = form.querySelector('input[name="items[' + itemId + '][da]"]');
                    const dr = form.querySelector('input[name="items[' + itemId + '][dr]"]');
                    const traffic = form.querySelector('input[name="items[' + itemId + '][traffic]"]');
                    const row = (country && country.closest('[data-bulk-done-row]'))
                        || (language && language.closest('[data-bulk-done-row]'))
                        || form.querySelector('[data-bulk-done-row][data-item-id="' + itemId + '"]');

                    const useDraftFields = serverOldItemIds.indexOf(itemId) === -1;
                    if (useDraftFields) {
                        if (country && data.country) country.value = data.country;
                        if (row) refreshBulkDoneLanguages(row, data.language || '');
                        if (language && data.language) language.value = data.language;
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
                    }

                    // Server-old reject notes win; draft fills any empty card (including after a Done error).
                    restoreRejectNote(itemId, data, row);
                } catch (e) {}
            });
        } catch (e) {}
    }

    function markRequiredField(el) {
        if (!el) return;
        const row = el.closest('[data-bulk-done-row]');
        if (el.type === 'hidden' && String(el.name || '').indexOf('[categories]') !== -1) {
            const picker = row && row.querySelector('.multi-select-input');
            if (picker) {
                picker.classList.add('is-invalid');
                if (typeof picker.focus === 'function') picker.focus();
                return;
            }
        }
        el.classList.add('is-invalid');
        if (el.type !== 'hidden' && typeof el.focus === 'function') {
            el.focus();
        }
    }

    function unmarkFilledField(el) {
        if (!el || !fieldFilled(el)) return;
        el.classList.remove('is-invalid');
        if (el.type === 'hidden' && String(el.name || '').indexOf('[categories]') !== -1) {
            const row = el.closest('[data-bulk-done-row]');
            const picker = row && row.querySelector('.multi-select-input');
            if (picker) picker.classList.remove('is-invalid');
        }
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

    function fields() {
        return doneRows().flatMap(rowFields);
    }

    function rowItemId(row) {
        if (!row) return null;
        const fromAttr = safeItemId(row.getAttribute('data-item-id'));
        if (fromAttr) return fromAttr;
        const language = row.querySelector('select[name*="[language]"]');
        const name = (language && language.name) || '';
        const match = name.match(/items\[(\d+)\]/);
        return match ? safeItemId(match[1]) : null;
    }

    function isRejectControl(el) {
        if (!el) return false;
        if (el.hasAttribute('data-bulk-reject-note') || el.hasAttribute('data-bulk-reject-submit')) {
            return true;
        }
        const formId = el.getAttribute('form') || '';
        return formId.indexOf('reject-item-') === 0;
    }

    function isClearControl(el) {
        return !!(el && (el.hasAttribute('data-bulk-done-clear') || el.closest('[data-bulk-done-clear]')));
    }

    function clearRow(row) {
        const country = row.querySelector('[data-bulk-country]');
        const da = row.querySelector('input[name*="[da]"]');
        const dr = row.querySelector('input[name*="[dr]"]');
        const traffic = row.querySelector('input[name*="[traffic]"]');
        const categories = row.querySelector('input[name*="[categories]"]');
        if (country) country.value = '';
        if (da) da.value = '';
        if (dr) dr.value = '';
        if (traffic) traffic.value = '';
        if (categories) categories.value = '';
        const itemId = rowItemId(row);
        if (itemId && multiSelects[itemId] && typeof multiSelects[itemId].clearSelections === 'function') {
            multiSelects[itemId].clearSelections();
        }
        refreshBulkDoneLanguages(row, '');
        row.querySelectorAll('.is-invalid').forEach(function (el) {
            if (!isRejectControl(el)) el.classList.remove('is-invalid');
        });
        writeDraft();
        syncDoneState();
    }

    function setIncompleteRowsDisabled(disabled) {
        doneRows().forEach(function (row) {
            if (rowFilled(row)) return;
            const countryEl = row.querySelector('[data-bulk-country]');
            row.querySelectorAll('select, input, textarea, button').forEach(function (el) {
                if (isRejectControl(el) || isClearControl(el)) return;
                if (!disabled && el.hasAttribute('data-bulk-language') && countryEl && !countryEl.value) {
                    el.disabled = true;
                    return;
                }
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
                hint.textContent = 'Finish each started row or click Clear. You can submit the '
                    + complete.length + ' complete block(s) after that.';
            } else if (complete.length === 0) {
                hint.textContent = 'Fill at least one complete block (Country, Language, DA, DR, Traffic, Niches) before Done.';
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
    const invalidReject = form.querySelector('[data-bulk-reject-note].is-invalid');
    if (invalidReject) {
        invalidReject.focus();
    }

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

    form.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-bulk-done-clear]');
        if (!btn || !form.contains(btn)) return;
        const row = btn.closest('[data-bulk-done-row]');
        if (row) clearRow(row);
    });

    form.addEventListener('input', function (e) {
        if (isRejectControl(e.target)) {
            scheduleDraftSave();
            return;
        }
        clampScoreInput(e.target);
        unmarkFilledField(e.target);
        syncDoneState();
        scheduleDraftSave();
    });
    form.addEventListener('change', function (e) {
        if (isRejectControl(e.target)) {
            writeDraft();
            return;
        }
        clampScoreInput(e.target);
        unmarkFilledField(e.target);
        syncDoneState();
        writeDraft();
    });
    window.addEventListener('pagehide', writeDraft);
    window.addEventListener('beforeunload', writeDraft);

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
                markRequiredField(firstPartial);
                slbAlert({
                    icon: 'warning',
                    title: 'Finish incomplete blocks',
                    text: 'Each started row must be fully filled, or click Clear. Then submit the complete block(s). Empty rows can wait for later.',
                });
            } else {
                const firstEmpty = fields().find((el) => !fieldFilled(el));
                markRequiredField(firstEmpty);
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
            title: 'Done — add draft sites?',
            text: remaining > 0
                ? ('Add ' + count + ' complete draft site(s) now and notify the publisher? ' + remaining + ' unfinished row(s) will stay pending.')
                : ('Add ' + count + ' draft site(s) to this publisher’s Pending sites and notify them?'),
            confirmText: 'Done',
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
                text: 'Delete draft "' + name + '"? This removes the wrong draft. History of the delete is kept.',
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
