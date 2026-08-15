@extends('advertiser.layouts.app')

@section('content')
@php
    $tab = $tab ?? 'upcoming';
    $counts = $counts ?? ['upcoming' => 0, 'with_publisher' => 0, 'history' => 0];
    $maxMonths = (int) ($maxMonths ?? 3);
    $maxDate = $maxDate ?? now()->addMonths($maxMonths)->toDateString();
    $timezones = $timezones ?? ['UTC'];
    $editable = (bool) ($editable ?? ($tab === 'upcoming'));
@endphp

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col-12">
            <h2 class="mb-1 fw-semibold">Scheduled Orders</h2>
            <p class="text-muted mb-2">
                Paid orders stay visible to the publisher. They should publish on this date.
                You can move the date, release early, or cancel for a refund while the order is still
                <strong>upcoming</strong> (up to {{ $maxMonths }} {{ $maxMonths === 1 ? 'month' : 'months' }} ahead).
            </p>
            <div class="alert alert-light border small mb-0">
                <strong>Cancel</strong> returns funds to your wallet and frees the article back to
                <a href="{{ route('advertiser.content-library') }}">Content Library</a>.
                Once the date is due (or you publish now), the order is with the publisher and can no longer be cancelled here.
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'upcoming' ? 'active' : '' }}"
               href="{{ route('advertiser.scheduled-orders', ['tab' => 'upcoming']) }}">
                Upcoming
                <span class="badge text-bg-secondary">{{ $counts['upcoming'] }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'with_publisher' ? 'active' : '' }}"
               href="{{ route('advertiser.scheduled-orders', ['tab' => 'with_publisher']) }}">
                With publisher
                <span class="badge text-bg-secondary">{{ $counts['with_publisher'] }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'history' ? 'active' : '' }}"
               href="{{ route('advertiser.scheduled-orders', ['tab' => 'history']) }}">
                History
                <span class="badge text-bg-secondary">{{ $counts['history'] }}</span>
            </a>
        </li>
    </ul>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order</th>
                            <th>Sites</th>
                            <th>Status</th>
                            <th>Scheduled for</th>
                            <th>Payment</th>
                            <th style="min-width:{{ $editable ? '340px' : '120px' }};">{{ $editable ? 'Actions' : 'Phase' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $order)
                            @php
                                $tz = $order->schedule_timezone ?: 'UTC';
                                try {
                                    new \DateTimeZone($tz);
                                } catch (\Throwable) {
                                    $tz = 'UTC';
                                }
                                $local = $order->scheduled_publish_at
                                    ? $order->scheduled_publish_at->copy()->timezone($tz)
                                    : null;
                                $orderFocusUrl = route('advertiser.orders', ['focus' => 'order', 'order' => $order->id]);
                                $statusLabel = str_replace('_', ' ', (string) $order->status);
                                $isPaid = ($order->payment_status ?? '') === 'paid';
                                $minDate = now($tz)->toDateString();
                                $rowMaxDate = now($tz)->addMonthsNoOverflow($maxMonths)->toDateString();
                                $phase = match (true) {
                                    $tab === 'history' => ucfirst((string) $order->status),
                                    $tab === 'upcoming' => 'Upcoming',
                                    $order->status === 'review' => 'Needs your review',
                                    default => 'Waiting on publisher',
                                };
                                $cancelConfirm = $isPaid
                                    ? 'Cancel this scheduled order? Funds return to your wallet and the article returns to Content Library.'
                                    : 'Cancel this scheduled order? The article returns to Content Library.';
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ $orderFocusUrl }}" class="fw-semibold text-decoration-none">#{{ $order->order_number }}</a>
                                    <div class="small text-muted">REF{{ $order->reference_code }}</div>
                                </td>
                                <td class="small">
                                    {{ $order->items->pluck('site_name')->filter()->implode(', ') ?: '—' }}
                                </td>
                                <td>
                                    <span class="small text-capitalize">{{ $statusLabel }}</span>
                                </td>
                                <td>
                                    @if($local)
                                        <div class="fw-semibold">{{ $local->format('d F Y') }}</div>
                                        <div class="small text-muted">{{ $local->format('g:i A') }} {{ $tz }}</div>
                                    @elseif($order->schedule_released_at)
                                        <span class="small text-muted">Released {{ $order->schedule_released_at->format('d M Y') }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <span class="badge text-bg-{{ $isPaid ? 'success' : ($order->payment_status === 'refunded' ? 'info' : 'warning') }}">
                                        {{ ucfirst((string) $order->payment_status) }}
                                    </span>
                                    @if($isPaid && $tab === 'upcoming')
                                        <div class="small text-muted mt-1">Funds held · refunded on cancel</div>
                                    @elseif($order->payment_status === 'refunded')
                                        <div class="small text-muted mt-1">Returned to wallet</div>
                                    @endif
                                </td>
                                <td>
                                    @if($editable)
                                        <div class="d-flex flex-wrap gap-2 align-items-end">
                                            <form method="POST" action="{{ route('advertiser.scheduled-orders.update', $order, false) }}" class="d-flex flex-wrap gap-2 align-items-end">
                                                @csrf
                                                <div>
                                                    <label class="form-label small mb-0">New date</label>
                                                    <input type="date" name="scheduled_date" class="form-control form-control-sm"
                                                           min="{{ $minDate }}"
                                                           max="{{ $rowMaxDate }}"
                                                           value="{{ $local?->toDateString() }}" required>
                                                </div>
                                                <div>
                                                    <label class="form-label small mb-0">Time</label>
                                                    <input type="time" name="scheduled_time" class="form-control form-control-sm" value="{{ $local?->format('H:i') ?? '09:00' }}">
                                                </div>
                                                <div>
                                                    <label class="form-label small mb-0">Timezone</label>
                                                    <select name="timezone" class="form-select form-select-sm">
                                                        @foreach($timezones as $zone)
                                                            <option value="{{ $zone }}" @selected($tz === $zone)>{{ $zone }}</option>
                                                        @endforeach
                                                        @if(! in_array($tz, $timezones, true))
                                                            <option value="{{ $tz }}" selected>{{ $tz }}</option>
                                                        @endif
                                                    </select>
                                                </div>
                                                <button type="submit" name="action" value="reschedule" class="btn btn-sm btn-outline-primary">Update</button>
                                            </form>
                                            @if($isPaid)
                                                <form method="POST" action="{{ route('advertiser.scheduled-orders.update', $order, false) }}">
                                                    @csrf
                                                    <button type="submit" name="action" value="publish_now" class="btn btn-sm btn-primary"
                                                            data-slb-confirm="Release this order to the publisher now? They will be notified to publish."
                                                            data-slb-confirm-title="Publish now?"
                                                            data-slb-confirm-text="Publish now"
                                                            data-slb-confirm-icon="question">Publish now</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('advertiser.scheduled-orders.update', $order, false) }}">
                                                @csrf
                                                <button type="submit" name="action" value="cancel" class="btn btn-sm btn-outline-danger"
                                                        data-slb-confirm="{{ $cancelConfirm }}"
                                                        data-slb-confirm-title="Cancel scheduled order?"
                                                        data-slb-confirm-text="Cancel order"
                                                        data-slb-confirm-danger="1">Cancel</button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="badge text-bg-light border">{{ $phase }}</span>
                                        <div class="mt-1">
                                            <a href="{{ $orderFocusUrl }}" class="small">View in Orders</a>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    @if($tab === 'upcoming')
                                        <p class="text-muted mb-3">No upcoming scheduled publications.</p>
                                        <a href="{{ route('advertiser.catalog') }}" class="btn btn-sm btn-primary me-2">Browse catalog</a>
                                        <a href="{{ route('advertiser.content-library') }}" class="btn btn-sm btn-outline-secondary">Content Library</a>
                                        <p class="small text-muted mt-3 mb-0">You can pick a future publish date at checkout.</p>
                                    @elseif($tab === 'with_publisher')
                                        <p class="text-muted mb-0">No orders waiting on a publisher right now.</p>
                                    @else
                                        <p class="text-muted mb-0">No scheduled order history yet.</p>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if(method_exists($orders, 'links'))
                <div class="p-3">{{ $orders->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
