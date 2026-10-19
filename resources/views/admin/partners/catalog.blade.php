@extends(staff_layout())

@section('title', 'Partner catalog')

@section('content')
@php
    $statuses = ['unused', 'in_review', 'live', 'paused', 'rejected', 'blocked', 'removed_at_source'];
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Partner site selection</h4>
            <p class="text-muted small mb-0">Both prices are admin-only. Go-live creates a normal marketplace listing owned by the partner’s publisher account. Teqno LTD — no partner fees.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.partners.index') }}" class="btn btn-sm btn-outline-secondary">Partners</a>
            <a href="{{ route('admin.partners.imports') }}" class="btn btn-sm btn-outline-secondary">Imports</a>
            <a href="{{ route('admin.partners.catalog.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary">Export CSV</a>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.partners.catalog') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Partner</label>
                <select name="partner_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($partners as $partner)
                        <option value="{{ $partner->id }}" @selected((string) request('partner_id') === (string) $partner->id)>{{ $partner->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-semibold">Country</label>
                <input class="form-control form-control-sm" name="country" value="{{ request('country') }}" placeholder="cz">
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-semibold">Language</label>
                <input class="form-control form-control-sm" name="language" value="{{ request('language') }}" placeholder="cs">
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-semibold">DR min</label>
                <input class="form-control form-control-sm" type="number" name="dr_min" value="{{ request('dr_min') }}">
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-semibold">DR max</label>
                <input class="form-control form-control-sm" type="number" name="dr_max" value="{{ request('dr_max') }}">
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-semibold">Traffic min</label>
                <input class="form-control form-control-sm" type="number" name="traffic_min" value="{{ request('traffic_min') }}">
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-semibold">Price min</label>
                <input class="form-control form-control-sm" type="number" step="0.01" name="price_min" value="{{ request('price_min') }}">
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-semibold">Price max</label>
                <input class="form-control form-control-sm" type="number" step="0.01" name="price_max" value="{{ request('price_max') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Niche</label>
                <input class="form-control form-control-sm" name="niche" value="{{ request('niche') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Search</label>
                <input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="domain or name">
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="flag_duplicate" value="1" @checked(request()->boolean('flag_duplicate'))>
                    <label class="form-check-label small">Duplicates</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="flag_unreachable" value="1" @checked(request()->boolean('flag_unreachable'))>
                    <label class="form-check-label small">Unreachable</label>
                </div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary" type="submit">Filter</button>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.partners.catalog') }}">Reset</a>
            </div>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.partners.catalog.bulk') }}" id="partnerCatalogBulk">
        @csrf
        <div class="card border-0 shadow-sm mb-2">
            <div class="card-body d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label small fw-semibold">Bulk action</label>
                    <select name="action" class="form-select form-select-sm" required>
                        <option value="go_live">Put live</option>
                        <option value="send_to_review">Send to review</option>
                        <option value="keep_unused">Keep unused</option>
                        <option value="pause">Un-list / pause</option>
                        <option value="reject">Reject</option>
                        <option value="set_price">Apply suggested SLB price</option>
                    </select>
                </div>
                <div>
                    <label class="form-label small fw-semibold">Pricing rule</label>
                    <select name="pricing_rule_id" class="form-select form-select-sm">
                        <option value="">Default</option>
                        @foreach($rules as $rule)
                            <option value="{{ $rule->id }}">{{ $rule->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label small fw-semibold">Reject reason</label>
                    <input class="form-control form-control-sm" name="reject_reason" placeholder="other">
                </div>
                <div>
                    <label class="form-label small fw-semibold">Override note</label>
                    <input class="form-control form-control-sm" name="override_reason" placeholder="why">
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="override_margin" value="1" id="override_margin">
                    <label class="form-check-label small" for="override_margin">Override margin floor</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="override_duplicate" value="1" id="override_duplicate">
                    <label class="form-check-label small" for="override_duplicate">Override duplicate</label>
                </div>
                <button class="btn btn-sm btn-primary" type="submit">Apply to selected</button>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="partnerCatalogCheckAll"></th>
                            <th>Domain</th>
                            <th>Partner</th>
                            <th>Status</th>
                            <th>Country</th>
                            <th>DR</th>
                            <th>Traffic</th>
                            <th>Partner €</th>
                            <th>SLB €</th>
                            <th>Comm. €</th>
                            <th>Margin</th>
                            <th>Flags</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $item)
                            <tr>
                                <td><input type="checkbox" name="ids[]" value="{{ $item->id }}" class="partner-catalog-id"></td>
                                <td>
                                    <div class="fw-semibold">{{ $item->domain }}</div>
                                    <div class="small text-muted">{{ $item->site_name }}</div>
                                </td>
                                <td>{{ $item->partner?->displayName() }}</td>
                                <td>{{ $item->listing_status }}</td>
                                <td>{{ $item->country }}/{{ $item->language }}</td>
                                <td>{{ $item->dr }}</td>
                                <td>{{ number_format((int) $item->traffic) }}</td>
                                <td>{{ number_format((float) $item->partner_rate_publish_eur, 2) }}</td>
                                <td>{{ $item->slb_price_publish_eur !== null ? number_format((float) $item->slb_price_publish_eur, 2) : '—' }}</td>
                                <td>{{ $item->commissionPublishEur() !== null ? number_format($item->commissionPublishEur(), 2) : '—' }}</td>
                                <td>{{ $item->marginPercent() !== null ? $item->marginPercent().'%' : '—' }}</td>
                                <td class="small">
                                    @if($item->casino) casino @endif
                                    @if($item->duplicate) duplicate @endif
                                    @if($item->unreachable) unreachable @endif
                                    @if($item->below_margin_floor) below floor @endif
                                    @if($item->unmapped_niche) unmapped niche @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="text-muted">No catalog items match.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </form>
    <div class="mt-3">{{ $items->links() }}</div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var all = document.getElementById('partnerCatalogCheckAll');
    if (!all) return;
    all.addEventListener('change', function () {
        document.querySelectorAll('.partner-catalog-id').forEach(function (el) { el.checked = all.checked; });
    });
})();
</script>
@endpush
