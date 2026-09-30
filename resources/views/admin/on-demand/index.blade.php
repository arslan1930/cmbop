@extends('admin.layouts.app')

@section('title', 'On-demand')

@section('content')
<link href="{{ asset('assets/css/single-select.css') }}?v={{ @filemtime(public_path('assets/css/single-select.css')) ?: '1' }}" rel="stylesheet">
@php
    $search = $search ?? '';
    $listOr = function (string $name, array $fallback): array {
        $old = old($name, $fallback);
        $items = \App\Models\OnDemandContact::decodeList($old);

        return $items !== [] ? $items : [''];
    };
    $siteValues = $listOr('site_url', $editing?->site_url ?? ['']);
    $emailValues = $listOr('email', $editing?->email ?? ['']);
    $viaValues = $listOr('contacted_via_email', $editing?->contacted_via_email ?? ['']);
    $callingCodes = \App\Support\PhoneCallingCodes::all();
    $oldWhatsapp = old('whatsapp');
    $oldWhatsappCodes = old('whatsapp_code');
    if (is_array($oldWhatsapp)) {
        $whatsappRows = [];
        foreach ($oldWhatsapp as $i => $num) {
            $raw = is_scalar($num) ? (string) $num : '';
            $code = is_array($oldWhatsappCodes) ? (string) ($oldWhatsappCodes[$i] ?? '') : '';
            if ($code === '' && (str_starts_with(trim($raw), '+') || str_starts_with(trim($raw), '00'))) {
                $whatsappRows[] = \App\Support\PhoneCallingCodes::split($raw);
            } else {
                $whatsappRows[] = [
                    'dial' => $code !== '' ? \App\Support\PhoneCallingCodes::digits($code) : \App\Support\PhoneCallingCodes::DEFAULT_DIAL,
                    'number' => $raw,
                ];
            }
        }
    } else {
        $whatsappRows = array_map(
            fn ($value) => \App\Support\PhoneCallingCodes::split((string) $value),
            $listOr('whatsapp', $editing?->whatsapp ?? [''])
        );
    }
    if ($whatsappRows === []) {
        $whatsappRows = [['dial' => \App\Support\PhoneCallingCodes::DEFAULT_DIAL, 'number' => '']];
    }
@endphp
<div class="container-fluid">
    <div class="admin-page-header d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">On-demand</h1>
            <p class="text-muted mb-0">Contact details for people who are not on the portal. Admin only.</p>
        </div>
        <a href="{{ route('admin.sites.index') }}" class="btn btn-sm btn-outline-secondary">Back to sites</a>
    </div>

    @if(! ($tableReady ?? false))
        <div class="alert alert-warning border-0 shadow-sm" role="status">On-demand contacts are not available yet.</div>
    @else
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">{{ $editing ? 'Edit contact' : 'Add contact' }}</h2>
                <form method="POST" action="{{ $editing ? route('admin.sites.on-demand.update', $editing) : route('admin.sites.on-demand.store') }}">
                    @csrf
                    @if($editing)
                        @method('PUT')
                    @endif
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Site name <span class="req" aria-hidden="true">*</span></label>
                            <div class="ondemand-repeat" data-name="site_url" data-type="text" data-placeholder="example.com" data-max="255">
                                @foreach($siteValues as $i => $value)
                                    <div class="input-group input-group-sm mb-2 ondemand-repeat-row">
                                        <input type="text" name="site_url[]" class="form-control @error('site_url') is-invalid @enderror" value="{{ $value }}" maxlength="255" placeholder="example.com" enterkeyhint="enter" title="Press Enter to add another site" @if($i === 0) required @endif>
                                        <button type="button" class="btn btn-outline-secondary ondemand-remove" title="Remove" aria-label="Remove">&times;</button>
                                    </div>
                                @endforeach
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary ondemand-add" data-target="site_url">Add site</button>
                            <div class="form-text">Stored as the site name only — no http or https. Press Enter to add another. Each name must be unique.</div>
                            @error('site_url')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email <span class="req" aria-hidden="true">*</span></label>
                            <div class="ondemand-repeat" data-name="email" data-type="email" data-placeholder="owner@example.com" data-max="255">
                                @foreach($emailValues as $i => $value)
                                    <div class="input-group input-group-sm mb-2 ondemand-repeat-row">
                                        <input type="email" name="email[]" class="form-control @error('email') is-invalid @enderror" value="{{ $value }}" maxlength="255" enterkeyhint="enter" title="Press Enter to add another email" @if($i === 0) required @endif>
                                        <button type="button" class="btn btn-outline-secondary ondemand-remove" title="Remove" aria-label="Remove">&times;</button>
                                    </div>
                                @endforeach
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary ondemand-add" data-target="email">Add email</button>
                            <div class="form-text">Press Enter in the field to add another.</div>
                            @error('email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Contact (WhatsApp) <span class="req" aria-hidden="true">*</span></label>
                            <div class="ondemand-repeat" data-name="whatsapp" data-type="tel" data-placeholder="Number" data-max="80" data-phone="1">
                                <template id="ondemandDialOptions">
                                    @foreach($callingCodes as $code)
                                        <div class="single-select-option" role="option" data-value="{{ $code['dial'] }}" data-label="{{ strtoupper($code['iso']) }} +{{ $code['dial'] }}" data-search="{{ strtolower($code['name'].' '.$code['iso'].' +'.$code['dial']) }}">{{ $code['name'] }} · {{ strtoupper($code['iso']) }} +{{ $code['dial'] }}</div>
                                    @endforeach
                                </template>
                                @foreach($whatsappRows as $i => $row)
                                    @php
                                        $dialLabel = '+'.$row['dial'];
                                        foreach ($callingCodes as $code) {
                                            if ($code['dial'] === $row['dial']) {
                                                $dialLabel = strtoupper($code['iso']).' +'.$code['dial'];
                                                break;
                                            }
                                        }
                                    @endphp
                                    <div class="ondemand-repeat-row ondemand-phone-row mb-2">
                                        <div class="single-select-wrapper ondemand-dial-wrap">
                                            <input type="hidden" name="whatsapp_code[]" value="{{ $row['dial'] }}">
                                            <div class="single-select-input single-select-input--sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Country code">
                                                <span class="single-select-value">{{ $dialLabel }}</span>
                                                <i class="fa fa-chevron-down single-select-arrow" aria-hidden="true"></i>
                                            </div>
                                            <div class="single-select-dropdown">
                                                <div class="single-select-search">
                                                    <input type="search" placeholder="Search country…" autocomplete="off" aria-label="Search country">
                                                </div>
                                                <div class="single-select-options" role="listbox">
                                                    @foreach($callingCodes as $code)
                                                        <div class="single-select-option{{ $row['dial'] === $code['dial'] ? ' selected' : '' }}" role="option" data-value="{{ $code['dial'] }}" data-label="{{ strtoupper($code['iso']) }} +{{ $code['dial'] }}" data-search="{{ strtolower($code['name'].' '.$code['iso'].' +'.$code['dial']) }}">{{ $code['name'] }} · {{ strtoupper($code['iso']) }} +{{ $code['dial'] }}</div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                        <input type="tel" name="whatsapp[]" class="form-control form-control-sm @error('whatsapp') is-invalid @enderror" value="{{ $row['number'] }}" maxlength="80" placeholder="Number" inputmode="tel" enterkeyhint="enter" title="Press Enter to add another contact" @if($i === 0) required @endif>
                                        <button type="button" class="btn btn-sm btn-outline-secondary ondemand-remove" title="Remove" aria-label="Remove">&times;</button>
                                    </div>
                                @endforeach
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary ondemand-add" data-target="whatsapp">Add contact</button>
                            <div class="form-text">Choose the country code, then the number. Press Enter to add another.</div>
                            @error('whatsapp')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">On which mail they contacted us <span class="req" aria-hidden="true">*</span></label>
                            <div class="ondemand-repeat" data-name="contacted_via_email" data-type="email" data-placeholder="" data-max="255">
                                @foreach($viaValues as $i => $value)
                                    <div class="input-group input-group-sm mb-2 ondemand-repeat-row">
                                        <input type="email" name="contacted_via_email[]" class="form-control @error('contacted_via_email') is-invalid @enderror" value="{{ $value }}" maxlength="255" enterkeyhint="enter" title="Press Enter to add another mail" @if($i === 0) required @endif>
                                        <button type="button" class="btn btn-outline-secondary ondemand-remove" title="Remove" aria-label="Remove">&times;</button>
                                    </div>
                                @endforeach
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary ondemand-add" data-target="contacted_via_email">Add mail</button>
                            <div class="form-text">Press Enter in the field to add another.</div>
                            @error('contacted_via_email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="notes">Notes</label>
                            <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror" rows="3" maxlength="5000">{{ old('notes', $editing->notes ?? '') }}</textarea>
                            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">{{ $editing ? 'Update' : 'Save' }}</button>
                            @if($editing)
                                <a href="{{ route('admin.sites.on-demand.index') }}" class="btn btn-outline-secondary">Cancel</a>
                            @endif
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.sites.on-demand.index') }}" class="admin-deposits-filters mb-3" style="max-width: 36rem;">
            <x-slb-search-field
                name="q"
                id="adminOnDemandSearch"
                :value="$search"
                placeholder="Search site, email, WhatsApp, notes…"
                label="Search contacts"
                label-class="form-label"
                input-class="form-control"
            />
        </form>

        <p class="ondemand-row-hint text-muted mb-2">Click a row to open the full contact.</p>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive admin-table-fit">
                    <table class="table align-middle mb-0 ondemand-table">
                        <thead>
                            <tr>
                                <th>Site</th>
                                <th>Email</th>
                                <th>WhatsApp</th>
                                <th>Contacted us on</th>
                                <th>Notes</th>
                                <th class="admin-actions-col"><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($contacts as $contact)
                                @php
                                    $sites = $contact->previewFor('site_url');
                                    $emails = $contact->previewFor('email');
                                    $phones = $contact->previewFor('whatsapp');
                                    $vias = $contact->previewFor('contacted_via_email');
                                @endphp
                                <tr class="ondemand-row" data-ondemand-id="{{ $contact->id }}" aria-expanded="false" title="Click to view details">
                                    <td>
                                        <div class="ondemand-site-cell">
                                            <div class="ondemand-site-head">
                                                <span class="ondemand-open-hint">
                                                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                                    Details
                                                </span>
                                            </div>
                                            @foreach($sites['items'] as $host)
                                                <div class="ondemand-stack-line">
                                                    <span class="ondemand-chip">{{ $host }}</span>
                                                    <a class="ondemand-visit" href="https://{{ $host }}" target="_blank" rel="noopener noreferrer" title="Open {{ $host }}" aria-label="Open {{ $host }}">
                                                        <i class="fa fa-external-link" aria-hidden="true"></i>
                                                    </a>
                                                </div>
                                            @endforeach
                                            @if($sites['more'] > 0)
                                                <span class="ondemand-more">+more</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        @foreach($emails['items'] as $item)
                                            <div class="ondemand-chip">{{ $item }}</div>
                                        @endforeach
                                        @if($emails['more'] > 0)
                                            <span class="ondemand-more">+more</span>
                                        @endif
                                    </td>
                                    <td>
                                        @foreach($phones['items'] as $item)
                                            @php $phone = \App\Support\PhoneCallingCodes::split((string) $item); @endphp
                                            <div class="ondemand-stack-line">
                                                <span class="ondemand-chip">+{{ $phone['dial'] }}</span>
                                                <span class="ondemand-chip">{{ $phone['number'] }}</span>
                                            </div>
                                        @endforeach
                                        @if($phones['more'] > 0)
                                            <span class="ondemand-more">+more</span>
                                        @endif
                                    </td>
                                    <td>
                                        @foreach($vias['items'] as $item)
                                            <div class="ondemand-chip">{{ $item }}</div>
                                        @endforeach
                                        @if($vias['more'] > 0)
                                            <span class="ondemand-more">+more</span>
                                        @endif
                                    </td>
                                    <td>{{ \Illuminate\Support\Str::limit((string) $contact->notes, 80) }}</td>
                                    <td class="ondemand-actions text-end text-nowrap">
                                        <a href="{{ route('admin.sites.on-demand.index', array_filter(['edit' => $contact->id, 'q' => $search !== '' ? $search : null])) }}"
                                           class="ondemand-icon-btn"
                                           title="Edit" aria-label="Edit contact">
                                            <i class="fa fa-edit" aria-hidden="true"></i>
                                        </a>
                                        <form method="POST" action="{{ route('admin.sites.on-demand.destroy', $contact) }}" class="d-inline"
                                              data-slb-confirm="Delete this contact? This cannot be undone."
                                              data-slb-confirm-title="Delete contact?"
                                              data-slb-confirm-text="Delete"
                                              data-slb-confirm-danger="1">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="ondemand-icon-btn is-danger" title="Delete" aria-label="Delete contact">
                                                <i class="fa fa-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <tr id="ondemand-details-{{ $contact->id }}" class="admin-expand-row">
                                    <td colspan="6">
                                        <div class="admin-expand-box">
                                            <div class="border rounded bg-white shadow-sm p-3">
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <strong>Sites</strong>
                                                        @forelse($sites['all'] as $host)
                                                            <div class="ondemand-stack-line">
                                                                <span>{{ $host }}</span>
                                                                <a class="ondemand-visit" href="https://{{ $host }}" target="_blank" rel="noopener noreferrer" title="Open {{ $host }}" aria-label="Open {{ $host }}">
                                                                    <i class="fa fa-external-link" aria-hidden="true"></i>
                                                                </a>
                                                            </div>
                                                        @empty
                                                            <div class="text-muted">—</div>
                                                        @endforelse
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Email</strong>
                                                        @forelse($emails['all'] as $item)
                                                            <div>{{ $item }}</div>
                                                        @empty
                                                            <div class="text-muted">—</div>
                                                        @endforelse
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>WhatsApp</strong>
                                                        @forelse($phones['all'] as $item)
                                                            @php $phone = \App\Support\PhoneCallingCodes::split((string) $item); @endphp
                                                            <div class="ondemand-stack-line">
                                                                <span>+{{ $phone['dial'] }}</span>
                                                                <span>{{ $phone['number'] }}</span>
                                                            </div>
                                                        @empty
                                                            <div class="text-muted">—</div>
                                                        @endforelse
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Contacted us on</strong>
                                                        @forelse($vias['all'] as $item)
                                                            <div>{{ $item }}</div>
                                                        @empty
                                                            <div class="text-muted">—</div>
                                                        @endforelse
                                                    </div>
                                                    <div class="col-12">
                                                        <strong>Notes</strong>
                                                        <div class="slb-text-break">@if($contact->notes !== null && $contact->notes !== ''){!! nl2br(e($contact->notes)) !!}@else<span class="text-muted">—</span>@endif</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-muted text-center py-4">
                                        {{ $search !== '' ? 'No contacts match that search.' : 'No on-demand contacts yet.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($contacts->hasPages())
                <div class="card-footer bg-white">{{ $contacts->links() }}</div>
            @endif
        </div>
    @endif
</div>

@endsection

@push('scripts')
<script src="{{ asset('assets/js/single-select.js') }}?v={{ @filemtime(public_path('assets/js/single-select.js')) ?: '1' }}"></script>
<script>
(function () {
    var maxItems = {{ (int) \App\Models\OnDemandContact::MAX_LIST_ITEMS }};

    function closeDialDropdowns(except) {
        document.querySelectorAll('.ondemand-dial-wrap .single-select-dropdown.show').forEach(function (dd) {
            if (except && dd === except) return;
            dd.classList.remove('show');
            var trigger = dd.previousElementSibling;
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    }

    function bindDial(wrap) {
        if (!wrap || wrap.dataset.bound === '1') return;
        wrap.dataset.bound = '1';
        var hidden = wrap.querySelector('input[name="whatsapp_code[]"]');
        var trigger = wrap.querySelector('.single-select-input');
        var valueEl = wrap.querySelector('.single-select-value');
        var dropdown = wrap.querySelector('.single-select-dropdown');
        var search = wrap.querySelector('.single-select-search input');
        if (!hidden || !trigger || !dropdown || !valueEl) return;

        trigger.addEventListener('click', function () {
            var open = !dropdown.classList.contains('show');
            closeDialDropdowns(dropdown);
            dropdown.classList.toggle('show', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && search) setTimeout(function () { search.focus(); }, 10);
        });

        wrap.querySelectorAll('.single-select-option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                hidden.value = opt.getAttribute('data-value') || '';
                valueEl.textContent = opt.getAttribute('data-label') || '';
                wrap.querySelectorAll('.single-select-option').forEach(function (o) { o.classList.remove('selected'); });
                opt.classList.add('selected');
                dropdown.classList.remove('show');
                trigger.setAttribute('aria-expanded', 'false');
            });
        });

        if (search) {
            search.addEventListener('input', function () {
                var q = this.value.trim().toLowerCase();
                wrap.querySelectorAll('.single-select-option').forEach(function (opt) {
                    var hay = (opt.getAttribute('data-search') || opt.getAttribute('data-label') || '').toLowerCase();
                    opt.classList.toggle('hidden', q !== '' && hay.indexOf(q) === -1);
                });
            });
        }
    }

    function addRow(wrap) {
        if (!wrap) return;
        if (wrap.querySelectorAll('.ondemand-repeat-row').length >= maxItems) return;
        var type = wrap.getAttribute('data-type') || 'text';
        var name = wrap.getAttribute('data-name');
        var placeholder = wrap.getAttribute('data-placeholder') || '';
        var max = wrap.getAttribute('data-max') || '255';
        var group = document.createElement('div');
        if (wrap.getAttribute('data-phone') === '1') {
            var lastWrap = wrap.querySelector('.ondemand-dial-wrap:last-of-type');
            var prevValue = lastWrap && lastWrap.querySelector('input[name="whatsapp_code[]"]')
                ? lastWrap.querySelector('input[name="whatsapp_code[]"]').value
                : '49';
            var prevLabel = lastWrap && lastWrap.querySelector('.single-select-value')
                ? lastWrap.querySelector('.single-select-value').textContent
                : 'DE +49';
            var options = document.getElementById('ondemandDialOptions');
            group.className = 'ondemand-repeat-row ondemand-phone-row mb-2';
            group.innerHTML = '<div class="single-select-wrapper ondemand-dial-wrap">'
                + '<input type="hidden" name="whatsapp_code[]" value="' + String(prevValue).replace(/"/g, '') + '">'
                + '<div class="single-select-input single-select-input--sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Country code">'
                + '<span class="single-select-value"></span><i class="fa fa-chevron-down single-select-arrow" aria-hidden="true"></i></div>'
                + '<div class="single-select-dropdown"><div class="single-select-search">'
                + '<input type="search" placeholder="Search country…" autocomplete="off" aria-label="Search country"></div>'
                + '<div class="single-select-options" role="listbox">' + (options ? options.innerHTML : '') + '</div></div></div>'
                + '<input type="tel" name="whatsapp[]" class="form-control form-control-sm" maxlength="' + max + '" placeholder="' + placeholder.replace(/"/g, '&quot;') + '" inputmode="tel" enterkeyhint="enter" title="Press Enter to add another">'
                + '<button type="button" class="btn btn-sm btn-outline-secondary ondemand-remove" title="Remove" aria-label="Remove">&times;</button>';
            wrap.appendChild(group);
            var valueEl = group.querySelector('.single-select-value');
            if (valueEl) valueEl.textContent = prevLabel;
            var match = group.querySelector('.single-select-option[data-value="' + prevValue + '"]');
            if (match) match.classList.add('selected');
            bindDial(group.querySelector('.ondemand-dial-wrap'));
            var tel = group.querySelector('input[name="whatsapp[]"]');
            if (tel) tel.focus();
            return;
        }
        group.className = 'input-group input-group-sm mb-2 ondemand-repeat-row';
        group.innerHTML = '<input type="' + type + '" name="' + name + '[]" class="form-control" maxlength="' + max + '" placeholder="' + placeholder.replace(/"/g, '&quot;') + '" enterkeyhint="enter" title="Press Enter to add another">'
            + '<button type="button" class="btn btn-outline-secondary ondemand-remove" title="Remove" aria-label="Remove">&times;</button>';
        wrap.appendChild(group);
        var input = group.querySelector('input');
        if (input) input.focus();
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) return;
        var input = e.target;
        if (!input || input.tagName !== 'INPUT' || !input.closest('.ondemand-repeat')) return;
        if (input.closest('.single-select-search') || input.type === 'search') return;
        e.preventDefault();
        addRow(input.closest('.ondemand-repeat'));
    });

    document.addEventListener('click', function (e) {
        var add = e.target.closest('.ondemand-add');
        if (add) {
            e.preventDefault();
            addRow(document.querySelector('.ondemand-repeat[data-name="' + add.getAttribute('data-target') + '"]'));
            return;
        }
        var remove = e.target.closest('.ondemand-remove');
        if (remove) {
            e.preventDefault();
            var wrap = remove.closest('.ondemand-repeat');
            var group = remove.closest('.ondemand-repeat-row');
            if (!wrap || !group) return;
            if (wrap.querySelectorAll('.ondemand-repeat-row').length <= 1) {
                var only = group.querySelector('input[name="whatsapp[]"], input[name$="[]"]');
                if (only && only.name !== 'whatsapp_code[]') only.value = '';
                return;
            }
            group.remove();
        }
    });

    document.querySelectorAll('.ondemand-dial-wrap').forEach(bindDial);

    function setDetailsOpen(id, opening) {
        var details = document.getElementById('ondemand-details-' + id);
        var row = document.querySelector('.ondemand-row[data-ondemand-id="' + id + '"]');
        if (!details) return;
        if (opening) {
            document.querySelectorAll('.admin-expand-row.is-open').forEach(function (openRow) {
                if (openRow === details) return;
                openRow.classList.remove('is-open');
                var otherId = String(openRow.id || '').replace(/^ondemand-details-/, '');
                var other = document.querySelector('.ondemand-row[data-ondemand-id="' + otherId + '"]');
                if (other) {
                    other.classList.remove('is-open');
                    other.setAttribute('aria-expanded', 'false');
                }
            });
            details.classList.add('is-open');
            if (row) row.classList.add('is-open');
        } else {
            details.classList.remove('is-open');
            if (row) row.classList.remove('is-open');
        }
        if (row) row.setAttribute('aria-expanded', opening ? 'true' : 'false');
    }

    function toggleDetails(row) {
        var id = row.getAttribute('data-ondemand-id');
        var details = document.getElementById('ondemand-details-' + id);
        if (!details) return;
        setDetailsOpen(id, !details.classList.contains('is-open'));
    }

    document.querySelectorAll('.ondemand-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button, form')) return;
            toggleDetails(row);
        });
    });
})();
</script>
@endpush
