@extends(staff_layout())

@section('title', 'Pricing preview')

@section('content')
<div class="container-fluid py-3">
    <a href="{{ route('admin.partners.pricing-rules.index') }}" class="small">← Pricing rules</a>
    <h4 class="fw-bold">Quote preview</h4>
    <p class="text-muted small">Admin-only. Partners never see this SLB price. Teqno LTD.</p>
    <form method="GET" class="card border-0 shadow-sm mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Partner rate €</label>
                <input class="form-control" type="number" step="0.01" name="partner_eur" value="{{ $partnerEur }}">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Rule</label>
                <select class="form-select" name="pricing_rule_id">
                    <option value="">Default</option>
                    @foreach(\App\Models\PartnerPricingRule::query()->where('active', true)->orderBy('name')->get() as $option)
                        <option value="{{ $option->id }}" @selected($rule && (int) $rule->id === (int) $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary" type="submit">Quote</button>
            </div>
        </div>
    </form>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <p class="mb-1">Partner rate: <strong>€{{ number_format($partnerEur, 2) }}</strong></p>
            <p class="mb-1">Suggested SLB price: <strong>€{{ number_format($quote['amount'], 2) }}</strong></p>
            <p class="mb-1">Commission: €{{ number_format($quote['amount'] - $partnerEur, 2) }}</p>
            <p class="mb-0 small text-muted">Jitter used in this quote: {{ $quote['jitter'] }} · rule {{ $rule?->name ?? 'default fallback' }}</p>
        </div>
    </div>
</div>
@endsection
