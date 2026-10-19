@extends('partner.layouts.app')
@section('title', 'Orders')
@section('content')
    <h1 class="partner-h1">Orders</h1>
    <form class="partner-filters" method="get">
        <select name="status">
            <option value="">All statuses</option>
            @foreach ($labels as $key => $label)
                <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Order ID, domain, or URL">
        <input type="date" name="from" value="{{ $filters['from'] }}">
        <input type="date" name="to" value="{{ $filters['to'] }}">
        <button class="partner-btn" type="submit">Filter</button>
    </form>
    <div class="partner-card">
        <table class="partner-table">
            <thead>
            <tr>
                <th>Order</th>
                <th>Status</th>
                <th>Site</th>
                <th>Target</th>
                <th>Your payout</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Order"><a href="{{ \App\Support\PartnerDashboard::route('orders.show', ['order' => $row['id']]) }}">{{ $row['order_number'] }}</a></td>
                    <td data-label="Status"><span class="partner-badge {{ $row['status'] === 'needs_correction' ? 'is-warn' : ($row['status'] === 'completed' ? 'is-ok' : ($row['status'] === 'rejected' ? 'is-bad' : '')) }}">{{ $row['status_label'] }}</span></td>
                    <td data-label="Site">{{ $row['site_domain'] }}</td>
                    <td data-label="Target">{{ \Illuminate\Support\Str::limit($row['target_url'], 48) }}</td>
                    <td data-label="Your payout">{{ $row['your_payout_formatted'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No orders match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="partner-pager">{{ $paginator->links() }}</div>
    </div>
@endsection
