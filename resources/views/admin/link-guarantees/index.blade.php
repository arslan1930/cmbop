@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Link guarantees',
        'subtitle' => '12-month optional add-on, only on listings that stay live 12 months or permanently. Monthly HTTP + href checks; 7-day restore window; SEOLinkBuildings refunds the guaranteed placement.',
    ])

    <form method="GET" action="{{ route('admin.link-guarantees.index') }}" class="d-flex flex-wrap gap-2 mb-3">
        <input type="text" name="q" value="{{ $q }}" class="form-control" style="max-width:16rem;" placeholder="Order number">
        <select name="status" class="form-select" style="max-width:12rem;">
            <option value="">Any status</option>
            @foreach(['pending','active','restore_due','refunded','expired'] as $st)
                <option value="{{ $st }}" @selected($status === $st)>{{ $st }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Guaranteed orders</strong></div>
        <div class="card-body p-0">
            @if(!$orders instanceof \Illuminate\Contracts\Pagination\Paginator || $orders->isEmpty())
                <p class="text-muted small p-3 mb-0">No guaranteed orders match.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Advertiser</th>
                                <th>Status</th>
                                <th>Covered</th>
                                <th>Fee</th>
                                <th>Restore due</th>
                                <th>Ends</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($orders as $order)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.orders.show', $order->id) }}">#{{ $order->order_number }}</a>
                                    </td>
                                    <td class="small">{{ $order->user?->email }}</td>
                                    <td>{{ $order->link_guarantee_status ?: '—' }}</td>
                                    <td>€{{ number_format((float) $order->link_guarantee_covered_amount, 2) }}</td>
                                    <td>€{{ number_format((float) $order->link_guarantee_fee, 2) }}</td>
                                    <td class="small">{{ $order->link_guarantee_restore_due_at?->format('Y-m-d') ?: '—' }}</td>
                                    <td class="small">{{ $order->link_guarantee_ends_at?->format('Y-m-d') ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $orders->links() }}</div>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><strong>Recent checks</strong></div>
        <div class="card-body p-0">
            @if($checks->isEmpty())
                <p class="text-muted small p-3 mb-0">No link checks recorded yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>HTTP</th>
                                <th>Href</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($checks as $check)
                                <tr>
                                    <td class="small">{{ $check->checked_at?->format('Y-m-d H:i') }}</td>
                                    <td>#{{ $check->order?->order_number }}</td>
                                    <td>{{ $check->status }}</td>
                                    <td>{{ $check->http_code ?: '—' }}</td>
                                    <td>{{ $check->href_found === null ? '—' : ($check->href_found ? 'yes' : 'no') }}</td>
                                    <td class="small text-muted">{{ $check->notes }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
