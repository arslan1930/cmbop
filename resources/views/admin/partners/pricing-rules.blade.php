@extends(staff_layout())

@section('title', 'Partner pricing rules')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Partner pricing rules</h4>
            <p class="text-muted small mb-0">Default: ×2.0 up to €50 partner rate, then lower tiers. SLB price rounds to end in 9, 0–5% jitter, 35% margin floor, €25 minimum commission. Teqno LTD — partners pay no fees.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.partners.pricing-rules.preview') }}" class="btn btn-sm btn-outline-secondary">Preview quote</a>
            <a href="{{ route('admin.partners.catalog') }}" class="btn btn-sm btn-outline-secondary">Selection</a>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.partners.pricing-rules.store') }}" class="card border-0 shadow-sm mb-4">
        @csrf
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Name</label>
                <input class="form-control" name="name" required>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Min commission €</label>
                <input class="form-control" type="number" step="0.01" name="min_commission_eur" value="25" required>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Rounding</label>
                <select class="form-select" name="rounding">
                    <option value="end_9">end in 9</option>
                    <option value="end_0">end in 0</option>
                    <option value="none">none</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Jitter % (0–5)</label>
                <input class="form-control" type="number" step="0.1" min="0" max="5" name="jitter_percent" value="5" required>
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Tiers JSON</label>
                <textarea class="form-control font-monospace" name="tiers_json" rows="4">[{"max_eur":50,"multiplier":2.0},{"max_eur":150,"multiplier":1.8},{"max_eur":400,"multiplier":1.6},{"max_eur":null,"multiplier":1.45}]</textarea>
            </div>
            <div class="col-md-3">
                <div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" checked><label class="form-check-label">Active</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="is_default" value="1"><label class="form-check-label">Make default</label></div>
            </div>
            <div class="col-12"><button class="btn btn-primary" type="submit">Add rule</button></div>
        </div>
    </form>

    @foreach($rules as $rule)
        <form method="POST" action="{{ route('admin.partners.pricing-rules.update', $rule) }}" class="card border-0 shadow-sm mb-3">
            @csrf
            <div class="card-body row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input class="form-control" name="name" value="{{ $rule->name }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Min commission €</label>
                    <input class="form-control" type="number" step="0.01" name="min_commission_eur" value="{{ $rule->min_commission_eur }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Rounding</label>
                    <select class="form-select" name="rounding">
                        <option value="end_9" @selected($rule->rounding === 'end_9')>end in 9</option>
                        <option value="end_0" @selected($rule->rounding === 'end_0')>end in 0</option>
                        <option value="none" @selected($rule->rounding === 'none')>none</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Jitter %</label>
                    <input class="form-control" type="number" step="0.1" min="0" max="5" name="jitter_percent" value="{{ $rule->jitter_percent }}" required>
                </div>
                <div class="col-md-12">
                    <label class="form-label fw-semibold">Tiers JSON</label>
                    <textarea class="form-control font-monospace" name="tiers_json" rows="3">{{ json_encode($rule->normalizedTiers()) }}</textarea>
                </div>
                <div class="col-md-4">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" @checked($rule->active)><label class="form-check-label">Active</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_default" value="1" @checked($rule->is_default)><label class="form-check-label">Default {{ $rule->is_default ? '(current)' : '' }}</label></div>
                </div>
                <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save</button></div>
            </div>
        </form>
    @endforeach
</div>
@endsection
