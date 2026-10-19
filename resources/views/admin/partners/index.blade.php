@extends(staff_layout())

@section('title', 'Partners')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Catalog partners</h4>
            <p class="text-muted small mb-0">A partner is a normal publisher account. Imported sites go live as their listings. Teqno LTD (17392540) — publishers pay no fees. They never see the SLB price.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.partners.catalog') }}" class="btn btn-sm btn-primary">Selection screen</a>
            <a href="{{ route('admin.partners.imports') }}" class="btn btn-sm btn-outline-secondary">Imports</a>
            <a href="{{ route('admin.partners.pricing-rules.index') }}" class="btn btn-sm btn-outline-secondary">Pricing rules</a>
            <a href="{{ route('admin.partners.auto-approve-rules.index') }}" class="btn btn-sm btn-outline-secondary">Auto-approve</a>
            <a href="{{ route('admin.partners.rate-changes.index') }}" class="btn btn-sm btn-outline-secondary">Rate changes</a>
            <a href="{{ route('admin.publishers.onboard') }}" class="btn btn-sm btn-outline-secondary">Add publisher</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h6 class="fw-semibold mb-3">Mark an existing publisher</h6>
            <form method="POST" action="{{ route('admin.partners.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Publisher user ID</label>
                    <input type="number" name="user_id" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Legal name</label>
                    <input type="text" name="legal_name" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="trusted" value="1" id="trusted">
                        <label class="form-check-label small" for="trusted">Trusted (rules may go live)</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="agreement_signed" value="1" id="agreement_signed">
                        <label class="form-check-label small" for="agreement_signed">Agreement signed</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary" type="submit">Mark as partner</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Partner</th>
                        <th>Publisher</th>
                        <th>Status</th>
                        <th>Trusted</th>
                        <th>Sites</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($partners as $partner)
                        <tr>
                            <td>{{ $partner->displayName() }}</td>
                            <td>
                                <a href="{{ route('admin.users.show', $partner->user) }}">{{ $partner->user?->email }}</a>
                            </td>
                            <td>{{ $partner->status }}</td>
                            <td>{{ $partner->trusted ? 'Yes' : 'No' }}</td>
                            <td>{{ (int) $partner->catalog_items_count }}</td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.partners.show', $partner) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">No partners yet. Add a publisher, then mark them here or tick Catalog partner on onboard.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $partners->links() }}</div>
</div>
@endsection
