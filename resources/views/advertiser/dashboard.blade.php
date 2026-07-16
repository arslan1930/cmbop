@extends('advertiser.layouts.app')

@section('content')

@php
    $stats = $stats ?? ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'cancelled' => 0];
    $recentOrders = $recentOrders ?? collect();
    $isNewAdvertiser = ($stats['total'] ?? 0) === 0;
@endphp

<style>
.get-started-steps { display: flex; flex-direction: column; gap: 12px; }
.get-started-step {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 14px 16px; border: 1px solid #e5e7eb; border-radius: 10px;
    background: #f8fafb; text-decoration: none; color: inherit;
    transition: border-color .2s ease, background .2s ease, transform .2s ease;
}
.get-started-step:hover { border-color: #4ECDCB; background: #f0fbfb; transform: translateY(-1px); color: inherit; }
.get-started-step .step-num {
    width: 28px; height: 28px; border-radius: 50%; background: #0b6266; color: #fff;
    font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.get-started-step .step-title { font-weight: 600; font-size: 14px; margin-bottom: 2px; }
.get-started-step .step-desc { font-size: 12px; color: #6b7280; margin: 0; }
.get-started-cta, .dash-primary-cta {
    background: linear-gradient(135deg, #3aaeb2, #0b6266); color: #fff; border: none;
    border-radius: 10px; padding: 12px 18px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
    transition: opacity .2s ease, transform .2s ease;
}
.get-started-cta:hover, .dash-primary-cta:hover { color: #fff; opacity: .95; transform: translateY(-1px); }
.kpi-tile {
    display: flex; align-items: center; gap: 12px; padding: 14px;
    border: 1px solid #e5eef0; border-radius: 10px; background: #fff; height: 100%;
}
.kpi-tile .kpi-icon {
    width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center;
    justify-content: center; color: #fff; flex-shrink: 0;
}
.kpi-tile .kpi-label { font-size: 12px; color: #6b7280; display: block; }
.kpi-tile .kpi-value { font-size: 1.35rem; font-weight: 700; color: #0b6266; line-height: 1.1; }
.next-action {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 12px 14px; border: 1px solid #e5e7eb; border-radius: 10px;
    text-decoration: none; color: inherit; background: #f8fafb;
    transition: border-color .2s ease, background .2s ease;
}
.next-action:hover { border-color: #4ECDCB; background: #f0fbfb; color: inherit; }
.next-action .na-title { font-weight: 600; font-size: 14px; }
.next-action .na-desc { font-size: 12px; color: #6b7280; margin: 0; }
.status-pill {
    display: inline-block; padding: 3px 8px; border-radius: 999px;
    font-size: 11px; font-weight: 600; text-transform: capitalize;
}
.status-pill.pending, .status-pill.processing, .status-pill.review { background: #fff3cd; color: #856404; }
.status-pill.completed { background: #d1e7dd; color: #0f5132; }
.status-pill.cancelled { background: #f8d7da; color: #842029; }
.help-secondary {
    border: 1px dashed #d7e7e8; border-radius: 12px; padding: 16px;
    background: #fafcfc;
}
</style>

<div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-4">
    <div>
        <h4 class="mb-1">Welcome back, {{ auth()->user()->name }}!</h4>
        <small class="text-muted">
            @if($isNewAdvertiser)
                Ready to place your first order? Follow the path below.
            @else
                Your command center — KPIs, next actions, and recent orders.
            @endif
        </small>
    </div>
    @unless($isNewAdvertiser)
        <a href="{{ route('advertiser.catalog') }}" class="dash-primary-cta">
            <i class="fa fa-list"></i> Browse catalog
        </a>
    @endunless
</div>

@if($isNewAdvertiser)
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <h5 class="mb-1">Get started</h5>
                    <p class="text-muted small mb-3">Three steps to your first placement.</p>
                    <div class="get-started-steps mb-3">
                        <a href="{{ route('advertiser.catalog') }}" class="get-started-step">
                            <span class="step-num">1</span>
                            <div>
                                <div class="step-title">Browse the catalog</div>
                                <p class="step-desc">Find sites that match your niche and market.</p>
                            </div>
                        </a>
                        <a href="{{ route('advertiser.add-funds') }}" class="get-started-step">
                            <span class="step-num">2</span>
                            <div>
                                <div class="step-title">Add funds</div>
                                <p class="step-desc">Top up your wallet so checkout is one click.</p>
                            </div>
                        </a>
                        <a href="{{ route('advertiser.catalog') }}" class="get-started-step">
                            <span class="step-num">3</span>
                            <div>
                                <div class="step-title">Place your first order</div>
                                <p class="step-desc">Add a site to cart, attach your article, and pay.</p>
                            </div>
                        </a>
                    </div>
                    <a href="{{ route('advertiser.catalog') }}" class="get-started-cta w-100 justify-content-center">
                        <i class="fa fa-list"></i> Browse catalog
                    </a>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="help-secondary h-100">
                <h6 class="mb-2">Need a hand?</h6>
                <p class="small text-muted mb-3">Message your client manager if you get stuck on catalog or checkout.</p>
                <a href="https://t.me/arslan_seolinkbuildings" target="_blank" class="btn btn-sm" style="background:#3aaeb2;color:#fff;">
                    <i class="fa fa-message me-1"></i> Start chat
                </a>
            </div>
        </div>
    </div>
@else
    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="kpi-tile">
                <div class="kpi-icon" style="background:#3aaeb2;"><i class="fa-solid fa-box-open"></i></div>
                <div>
                    <span class="kpi-label">Total orders</span>
                    <div class="kpi-value">{{ $stats['total'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-tile">
                <div class="kpi-icon" style="background:#198754;"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <span class="kpi-label">Completed</span>
                    <div class="kpi-value">{{ $stats['completed'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-tile">
                <div class="kpi-icon" style="background:#ffc107;color:#212529;"><i class="fa-solid fa-clock"></i></div>
                <div>
                    <span class="kpi-label">In progress</span>
                    <div class="kpi-value">{{ $stats['in_progress'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-tile">
                <div class="kpi-icon" style="background:#dc3545;"><i class="fa-solid fa-xmark-circle"></i></div>
                <div>
                    <span class="kpi-label">Cancelled</span>
                    <div class="kpi-value">{{ $stats['cancelled'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Next actions -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <h5 class="mb-3">Next actions</h5>
                    <div class="d-flex flex-column gap-2">
                        <a href="{{ route('advertiser.orders') }}" class="next-action">
                            <div>
                                <div class="na-title">Review orders</div>
                                <p class="na-desc">{{ $stats['in_progress'] }} in progress right now</p>
                            </div>
                            <i class="fa fa-chevron-right text-muted"></i>
                        </a>
                        <a href="{{ route('advertiser.catalog') }}" class="next-action">
                            <div>
                                <div class="na-title">Find new placements</div>
                                <p class="na-desc">Browse verified publisher sites</p>
                            </div>
                            <i class="fa fa-chevron-right text-muted"></i>
                        </a>
                        <a href="{{ route('advertiser.add-funds') }}" class="next-action">
                            <div>
                                <div class="na-title">Add funds</div>
                                <p class="na-desc">Keep wallet ready for checkout</p>
                            </div>
                            <i class="fa fa-chevron-right text-muted"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent orders -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Recent orders</h5>
                        <a href="{{ route('advertiser.orders') }}" class="small" style="color:#0b6266;font-weight:600;">View all</a>
                    </div>
                    @if($recentOrders->isEmpty())
                        <p class="text-muted small mb-0">No orders yet.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr class="text-muted small">
                                        <th>Order</th>
                                        <th>Site</th>
                                        <th>Status</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentOrders as $order)
                                        @php $firstItem = $order->items->first(); @endphp
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">#{{ $order->order_number ?? $order->id }}</div>
                                                <small class="text-muted">{{ $order->created_at?->format('M j, Y') }}</small>
                                            </td>
                                            <td>
                                                <div class="small fw-semibold">{{ $firstItem->site_name ?? '—' }}</div>
                                                @if(($order->items->count() ?? 0) > 1)
                                                    <small class="text-muted">+{{ $order->items->count() - 1 }} more</small>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="status-pill {{ $order->status }}">{{ $order->status }}</span>
                                            </td>
                                            <td class="text-end fw-semibold" style="color:#3aaeb2;">
                                                €{{ number_format((float) $order->total_amount, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="help-secondary">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <strong>Need assistance?</strong>
                <span class="text-muted small ms-1">Client manager · Mon–Fri, 9AM–6PM UTC</span>
            </div>
            <a href="https://t.me/arslan_seolinkbuildings" target="_blank" class="btn btn-sm" style="background:#3aaeb2;color:#fff;">
                <i class="fa fa-message me-1"></i> Start chat
            </a>
        </div>
    </div>
@endif

@endsection
