@extends(staff_layout())

@section('title', 'Partner auto-approve')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Auto-approve rules</h4>
            <p class="text-muted small mb-0">Rules may only send to review, except trusted partners may go live. Hard checks (casino, duplicate, unreachable) always apply. Starting rule: DR ≥ 30, traffic ≥ 2,000, dofollow, sample post, no sensitive-only niches.</p>
        </div>
        <a href="{{ route('admin.partners.catalog') }}" class="btn btn-sm btn-outline-secondary">Selection</a>
    </div>

    @if($dryRun)
        <div class="alert alert-info">Dry-run for <strong>{{ $dryRun['rule']->name }}</strong>: {{ $dryRun['count'] }} unused site(s) would match. IDs: {{ implode(', ', array_slice($dryRun['ids'], 0, 20)) }}{{ count($dryRun['ids']) > 20 ? '…' : '' }}</div>
    @endif

    <form method="POST" action="{{ route('admin.partners.auto-approve-rules.store') }}" class="card border-0 shadow-sm mb-4">
        @csrf
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Name</label>
                <input class="form-control" name="name" required>
            </div>
            <div class="col-md-1">
                <label class="form-label fw-semibold">Priority</label>
                <input class="form-control" type="number" name="priority" value="100" required>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Partner</label>
                <select class="form-select" name="partner_id">
                    <option value="">All partners</option>
                    @foreach($partners as $partner)
                        <option value="{{ $partner->id }}">{{ $partner->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label fw-semibold">Min DR</label>
                <input class="form-control" type="number" name="min_dr" value="30">
            </div>
            <div class="col-md-1">
                <label class="form-label fw-semibold">Min DA</label>
                <input class="form-control" type="number" name="min_da">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Min traffic</label>
                <input class="form-control" type="number" name="min_monthly_traffic" value="2000">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Max partner €</label>
                <input class="form-control" type="number" step="0.01" name="max_partner_rate_eur">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Action</label>
                <select class="form-select" name="action">
                    <option value="send_to_review">send to review</option>
                    <option value="go_live">go live (trusted only)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Pricing rule</label>
                <select class="form-select" name="pricing_rule_id">
                    <option value="">Default</option>
                    @foreach($pricingRules as $rule)
                        <option value="{{ $rule->id }}">{{ $rule->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Link types</label>
                <select class="form-select" name="link_types[]" multiple>
                    <option value="dofollow" selected>dofollow</option>
                    <option value="nofollow">nofollow</option>
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check"><input class="form-check-input" type="checkbox" name="require_sample_post" value="1" checked><label class="form-check-label">Require sample post</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="reject_sensitive_only" value="1" checked><label class="form-check-label">Skip sensitive-only niches</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" checked><label class="form-check-label">Active</label></div>
            </div>
            <div class="col-12"><button class="btn btn-primary" type="submit">Add rule</button></div>
        </div>
    </form>

    @foreach($rules as $rule)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.partners.auto-approve-rules.update', $rule) }}" class="row g-3">
                    @csrf
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Name</label>
                        <input class="form-control" name="name" value="{{ $rule->name }}" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold">Priority</label>
                        <input class="form-control" type="number" name="priority" value="{{ $rule->priority }}" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Partner</label>
                        <select class="form-select" name="partner_id">
                            <option value="">All partners</option>
                            @foreach($partners as $partner)
                                <option value="{{ $partner->id }}" @selected((int) $rule->partner_id === (int) $partner->id)>{{ $partner->displayName() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold">Min DR</label>
                        <input class="form-control" type="number" name="min_dr" value="{{ $rule->min_dr }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Min traffic</label>
                        <input class="form-control" type="number" name="min_monthly_traffic" value="{{ $rule->min_monthly_traffic }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Action</label>
                        <select class="form-select" name="action">
                            <option value="send_to_review" @selected($rule->action === 'send_to_review')>send to review</option>
                            <option value="go_live" @selected($rule->action === 'go_live')>go live (trusted only)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Pricing rule</label>
                        <select class="form-select" name="pricing_rule_id">
                            <option value="">Default</option>
                            @foreach($pricingRules as $pricing)
                                <option value="{{ $pricing->id }}" @selected((int) $rule->pricing_rule_id === (int) $pricing->id)>{{ $pricing->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Link types</label>
                        <select class="form-select" name="link_types[]" multiple>
                            <option value="dofollow" @selected(in_array('dofollow', $rule->link_types ?? [], true))>dofollow</option>
                            <option value="nofollow" @selected(in_array('nofollow', $rule->link_types ?? [], true))>nofollow</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="require_sample_post" value="1" @checked($rule->require_sample_post)><label class="form-check-label">Require sample post</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="reject_sensitive_only" value="1" @checked($rule->reject_sensitive_only)><label class="form-check-label">Skip sensitive-only niches</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" @checked($rule->active)><label class="form-check-label">Active</label></div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-sm btn-primary" type="submit">Save</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('admin.partners.auto-approve-rules.dry-run', $rule) }}" class="mt-2">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Dry-run</button>
                </form>
            </div>
        </div>
    @endforeach
</div>
@endsection
