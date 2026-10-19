@extends('partner.layouts.app')
@section('title', 'Money')
@section('content')
    <h1 class="partner-h1">Money</h1>
    <p class="partner-lead">Same publisher wallet as the main site. Minimum withdrawal €{{ number_format($minWithdrawal, 2) }}. No fees.</p>
    <div class="partner-tiles">
        <div class="partner-tile">
            <strong>{{ $currency === 'EUR' ? '€' : $currency.' ' }}{{ number_format($available, 2) }}</strong>
            <span>Available to withdraw</span>
        </div>
        <div class="partner-tile">
            <strong>{{ number_format($feePercent, 0) }}%</strong>
            <span>Withdrawal fee</span>
        </div>
    </div>

    <div class="partner-card">
        <h2>Request a withdrawal</h2>
        @if ($availableMethods === [])
            <p>Add a payout method on the main site first, then come back here.</p>
        @else
            <form method="post" action="{{ \App\Support\PartnerDashboard::route('money.withdraw') }}" class="partner-form">
                @csrf
                <div class="stack">
                    <label>Amount ({{ $currency }})
                        <input type="number" name="amount" min="{{ $minWithdrawal }}" step="0.01" value="{{ old('amount') }}" required>
                    </label>
                    <input type="hidden" name="currency" value="{{ $currency }}">
                    <label>Method
                        <select name="payment_method" required>
                            @foreach ($availableMethods as $method)
                                <option value="{{ $method }}">{{ ucfirst($method) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="partner-btn" type="submit">Request withdrawal</button>
                </div>
            </form>
        @endif
    </div>

    <div class="partner-card">
        <h2>Earnings</h2>
        <table class="partner-table">
            <thead><tr><th>When</th><th>Type</th><th>Amount</th></tr></thead>
            <tbody>
            @forelse ($transactions as $tx)
                <tr>
                    <td data-label="When">{{ optional($tx->created_at)->toDayDateTimeString() }}</td>
                    <td data-label="Type">{{ str_replace('_', ' ', (string) $tx->type) }}</td>
                    <td data-label="Amount">{{ $currency === 'EUR' ? '€' : $currency.' ' }}{{ number_format((float) $tx->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No earnings yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="partner-card">
        <h2>Withdrawals</h2>
        <table class="partner-table">
            <thead><tr><th>When</th><th>Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($withdrawals as $row)
                <tr>
                    <td data-label="When">{{ optional($row->created_at)->toDayDateTimeString() }}</td>
                    <td data-label="Amount">{{ $currency === 'EUR' ? '€' : $currency.' ' }}{{ number_format((float) $row->amount, 2) }}</td>
                    <td data-label="Status">{{ $row->status }}</td>
                    <td data-label="Cancel">
                        @if ($row->status === 'pending')
                            <form method="post" action="{{ \App\Support\PartnerDashboard::route('money.cancel', ['id' => $row->id]) }}" class="partner-inline-form">
                                @csrf
                                <button class="partner-btn secondary" type="submit">Cancel</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4">No withdrawals yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
