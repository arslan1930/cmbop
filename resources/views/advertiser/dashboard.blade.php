@extends('advertiser.layouts.app')

@section('content')

@php
    $stats = $stats ?? ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'cancelled' => 0];
    $recentOrders = $recentOrders ?? collect();
    $recommendedSites = $recommendedSites ?? collect();
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
.order-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
    color: #334155;
    text-transform: capitalize;
    background: none;
    border: none;
    padding: 0;
}
.order-status-dot {
    --status-dot: #3aaeb2;
    position: relative;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--status-dot);
    flex-shrink: 0;
}
.order-status-dot::after {
    content: "";
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    background: var(--status-dot);
    opacity: 0.35;
    animation: order-status-pulse 1.8s ease-out infinite;
}
.order-status.pending .order-status-dot { --status-dot: #64748b; }
.order-status.processing .order-status-dot,
.order-status.review .order-status-dot { --status-dot: #3aaeb2; }
.order-status.completed .order-status-dot { --status-dot: #0f766e; }
.order-status.cancelled .order-status-dot {
    --status-dot: #94a3b8;
}
.order-status.cancelled .order-status-dot::after,
.order-status.completed .order-status-dot::after {
    animation: none;
    opacity: 0;
}
@keyframes order-status-pulse {
    0% { transform: scale(0.7); opacity: 0.45; }
    70% { transform: scale(1.9); opacity: 0; }
    100% { transform: scale(1.9); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .order-status-dot::after { animation: none !important; opacity: 0; }
}
.recent-orders-glass {
    position: relative;
    height: 100%;
    border-radius: 18px;
    border: 1px solid rgba(255, 255, 255, 0.55);
    background: linear-gradient(145deg, rgba(255,255,255,0.72), rgba(240,251,251,0.55));
    box-shadow:
        0 18px 40px rgba(11, 98, 102, 0.1),
        inset 0 1px 0 rgba(255,255,255,0.75);
    backdrop-filter: blur(16px) saturate(1.35);
    -webkit-backdrop-filter: blur(16px) saturate(1.35);
    overflow: hidden;
}
.recent-orders-glass::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 55% 40% at 12% 0%, rgba(78, 205, 203, 0.22), transparent 60%),
        radial-gradient(ellipse 45% 35% at 90% 100%, rgba(11, 98, 102, 0.08), transparent 55%);
    pointer-events: none;
}
.recent-orders-glass .card-body { position: relative; z-index: 1; }
.recent-orders-glass .table { --bs-table-bg: transparent; }
.recent-orders-glass .table > :not(caption) > * > * {
    background: transparent; border-bottom-color: rgba(11, 98, 102, 0.08);
}
.recent-orders-glass thead th {
    font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
    color: #64748b !important; font-weight: 700; border-bottom-width: 1px;
}
.recent-orders-glass tbody tr {
    transition: background .2s ease;
}
.recent-orders-glass tbody tr:hover {
    background: rgba(255,255,255,0.45);
}
.recent-order-num {
    font-weight: 700; font-size: 15px; color: #0b6266; letter-spacing: .02em;
}
.recent-order-site {
    font-size: 13px; font-weight: 600; color: #1f2937; margin-top: 4px;
}
.recent-order-url {
    font-size: 12px; color: #64748b; text-decoration: none;
}
.recent-order-url:hover { color: #0b6266; }
.recent-orders-title {
    font-weight: 700; color: #0b6266; letter-spacing: -.01em;
}
.recent-orders-link {
    color: #0b6266; font-weight: 600; text-decoration: none;
}
.recent-orders-link:hover { color: #3aaeb2; }
.help-secondary {
    border: 1px dashed #d7e7e8; border-radius: 12px; padding: 16px;
    background: #fafcfc;
}
.recommended-sites { display: grid; gap: 10px; }
.recommended-site {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 12px 14px; border: 1px solid #e5e7eb; border-radius: 10px;
    background: #fff; color: inherit;
    transition: border-color .2s ease, background .2s ease;
}
.recommended-site:hover { border-color: #4ECDCB; background: #f0fbfb; }
.recommended-site .rs-name {
    font-weight: 400;
    font-size: 14px;
    color: #0b6266;
    text-decoration: underline;
    text-underline-offset: 2px;
    word-break: break-all;
}
.recommended-site .rs-name:hover { color: #3aaeb2; }
.recommended-site .rs-meta { font-size: 12px; color: #64748b; margin: 0; }
.recommended-site .rs-price {
    font-weight: 600;
    color: #0b6266;
    white-space: nowrap;
    text-decoration: none;
}
.recommended-site .rs-price:hover { color: #3aaeb2; }
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
            <div class="dash-panel h-100">
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
        <div class="col-lg-5">
            <div class="dash-panel h-100 mb-3">
                <h6 class="mb-1">Recommended for you</h6>
                <p class="small text-muted mb-3">Top verified placements to start with.</p>
                @if($recommendedSites->isEmpty())
                    <p class="small text-muted mb-0">Open the catalog to explore live inventory.</p>
                @else
                    <div class="recommended-sites">
                        @foreach($recommendedSites as $site)
                            @php
                                $displayUrl = (string) \Illuminate\Support\Str::of($site->site_url)
                                    ->replaceMatches('/^(https?:\/\/)?(www\.)?/', '')
                                    ->before('/');
                                $href = \Illuminate\Support\Str::startsWith($site->site_url, ['http://', 'https://'])
                                    ? $site->site_url
                                    : 'https://' . ltrim((string) $site->site_url, '/');
                            @endphp
                            <div class="recommended-site">
                                <div>
                                    <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="rs-name">{{ $displayUrl }}</a>
                                    <p class="rs-meta mb-0">DR {{ $site->dr }} · {{ fullLanguage($site->language) }}</p>
                                </div>
                                <a href="{{ route('advertiser.catalog', ['sort' => 'dr_desc']) }}" class="rs-price">€{{ number_format($site->display_price, 2) }}</a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="help-secondary">
                <h6 class="mb-2">Need a hand?</h6>
                <p class="small text-muted mb-3">Message your client manager if you get stuck on catalog or checkout.</p>
                <a href="https://t.me/arslan_seolinkbuildings" target="_blank" class="btn btn-sm btn-primary">
                    <i class="fa fa-message me-1" aria-hidden="true"></i> Start chat
                </a>
            </div>
        </div>
    </div>
@else
<style>
.dash-command-surface {
    position: relative;
    border-radius: 20px;
    padding: 4px;
    background:
        radial-gradient(ellipse 50% 60% at 80% 20%, rgba(78, 205, 203, 0.18), transparent 55%),
        radial-gradient(ellipse 40% 50% at 10% 80%, rgba(11, 98, 102, 0.08), transparent 50%),
        linear-gradient(180deg, #eef7f7 0%, #f8f9fa 100%);
}
</style>
<div class="dash-command-surface mb-1">
    <!-- KPIs -->
    <div class="row g-3 mb-4 px-1 pt-1">
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
        <!-- Next actions + recommended -->
        <div class="col-lg-4">
            <div class="dash-panel h-100">
                <h5 class="mb-3">Next actions</h5>
                <div class="d-flex flex-column gap-2 mb-3">
                    <a href="{{ route('advertiser.catalog') }}" class="next-action">
                        <div>
                            <div class="na-title">Browse catalog</div>
                            <p class="na-desc">Primary path — find verified placements</p>
                        </div>
                        <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                    <a href="{{ route('advertiser.orders') }}" class="next-action">
                        <div>
                            <div class="na-title">Review orders</div>
                            <p class="na-desc">{{ $stats['in_progress'] }} in progress right now</p>
                        </div>
                        <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                    <a href="{{ route('advertiser.add-funds') }}" class="next-action">
                        <div>
                            <div class="na-title">Add funds</div>
                            <p class="na-desc">Keep wallet ready for checkout</p>
                        </div>
                        <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                    <a href="{{ route('advertiser.analytics') }}" class="next-action">
                        <div>
                            <div class="na-title">Spending history</div>
                            <p class="na-desc">View spend by order, day, or month</p>
                        </div>
                        <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                </div>
                @if($recommendedSites->isNotEmpty())
                    <h6 class="mb-2">Recommended</h6>
                    <div class="recommended-sites">
                        @foreach($recommendedSites as $site)
                            @php
                                $displayUrl = (string) \Illuminate\Support\Str::of($site->site_url)
                                    ->replaceMatches('/^(https?:\/\/)?(www\.)?/', '')
                                    ->before('/');
                                $href = \Illuminate\Support\Str::startsWith($site->site_url, ['http://', 'https://'])
                                    ? $site->site_url
                                    : 'https://' . ltrim((string) $site->site_url, '/');
                            @endphp
                            <div class="recommended-site">
                                <div>
                                    <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="rs-name">{{ $displayUrl }}</a>
                                    <p class="rs-meta mb-0">DR {{ $site->dr }}</p>
                                </div>
                                <a href="{{ route('advertiser.catalog', ['sort' => 'dr_desc']) }}" class="rs-price">€{{ number_format($site->display_price, 2) }}</a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <!-- Recent orders -->
        <div class="col-lg-8">
            <div class="recent-orders-glass">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 recent-orders-title">Recent orders</h5>
                        <a href="{{ route('advertiser.orders') }}" class="small recent-orders-link">View all</a>
                    </div>
                    @if($recentOrders->isEmpty())
                        <p class="text-muted small mb-0">No orders yet.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Status</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentOrders as $order)
                                        @php
                                            $firstItem = $order->items->first();
                                            $numericOrder = preg_replace('/\D+/', '', (string) ($order->order_number ?? '')) ?: (string) $order->id;
                                            $statusLabel = str_replace('_', ' ', (string) $order->status);
                                        @endphp
                                        <tr>
                                            <td class="py-3">
                                                <div class="recent-order-num">#{{ $numericOrder }}</div>
                                                <div class="recent-order-site">{{ $firstItem->site_name ?? '—' }}</div>
                                                @if(!empty($firstItem->site_url))
                                                    <a href="{{ $firstItem->site_url }}" target="_blank" rel="noopener" class="recent-order-url">
                                                        {{ \Illuminate\Support\Str::limit($firstItem->site_url, 48) }}
                                                        <i class="fa fa-external-link fa-xs"></i>
                                                    </a>
                                                @endif
                                                @if(($order->items->count() ?? 0) > 1)
                                                    <div class="small text-muted mt-1">+{{ $order->items->count() - 1 }} more site{{ $order->items->count() - 1 === 1 ? '' : 's' }}</div>
                                                @endif
                                                <div class="small text-muted mt-1">{{ $order->created_at?->format('M j, Y') }}</div>
                                            </td>
                                            <td class="py-3">
                                                <span class="order-status {{ $order->status }}">
                                                    <span class="order-status-dot" aria-hidden="true"></span>
                                                    {{ $statusLabel }}
                                                </span>
                                            </td>
                                            <td class="text-end py-3 fw-semibold" style="color:#0b6266;">
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

    <div class="help-secondary mx-1 mb-1">
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
</div>
@endif

@endsection
