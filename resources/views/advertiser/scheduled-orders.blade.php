@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-1 fw-semibold">Scheduled Orders</h2>
            <p class="text-muted mb-0">Manage publication dates before orders are released to publishers (up to 3 months ahead).</p>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order</th>
                            <th>Sites</th>
                            <th>Scheduled for</th>
                            <th>Payment</th>
                            <th style="width:320px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $order)
                            @php
                                $tz = $order->schedule_timezone ?: 'UTC';
                                $local = $order->scheduled_publish_at
                                    ? $order->scheduled_publish_at->copy()->timezone($tz)
                                    : null;
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">#{{ $order->order_number }}</div>
                                    <div class="small text-muted">REF{{ $order->reference_code }}</div>
                                </td>
                                <td class="small">
                                    {{ $order->items->pluck('site_name')->filter()->implode(', ') }}
                                </td>
                                <td>
                                    @if($local)
                                        <div class="fw-semibold">{{ $local->format('d F Y') }}</div>
                                        <div class="small text-muted">{{ $local->format('g:i A') }} {{ $tz }}</div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <span class="badge text-bg-{{ $order->payment_status === 'paid' ? 'success' : 'warning' }}">
                                        {{ ucfirst($order->payment_status) }}
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('advertiser.scheduled-orders.update', $order) }}" class="d-flex flex-wrap gap-2 align-items-end">
                                        @csrf
                                        <div>
                                            <label class="form-label small mb-0">New date</label>
                                            <input type="date" name="scheduled_date" class="form-control form-control-sm"
                                                   min="{{ now()->toDateString() }}"
                                                   max="{{ now()->addMonths(3)->toDateString() }}"
                                                   value="{{ $local?->toDateString() }}" required>
                                        </div>
                                        <div>
                                            <label class="form-label small mb-0">Time</label>
                                            <input type="time" name="scheduled_time" class="form-control form-control-sm" value="{{ $local?->format('H:i') ?? '09:00' }}">
                                        </div>
                                        <input type="hidden" name="timezone" value="{{ $tz }}">
                                        <button type="submit" name="action" value="reschedule" class="btn btn-sm btn-outline-primary">Update</button>
                                        <button type="submit" name="action" value="publish_now" class="btn btn-sm btn-primary"
                                                data-slb-confirm="Release this order to the publisher now?"
                                                data-slb-confirm-title="Publish now?"
                                                data-slb-confirm-text="Publish now"
                                                data-slb-confirm-icon="question">Publish now</button>
                                        <button type="submit" name="action" value="cancel" class="btn btn-sm btn-outline-danger"
                                                data-slb-confirm="Cancel this scheduled order? The hold will be released."
                                                data-slb-confirm-title="Cancel scheduled order?"
                                                data-slb-confirm-text="Cancel order"
                                                data-slb-confirm-danger="1">Cancel</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">No scheduled orders right now.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
