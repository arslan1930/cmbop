@extends('partner.layouts.app')
@section('title', 'Money')
@section('content')
    <h1 class="partner-h1">Money</h1>
    <p class="partner-lead">Your payouts from Teqno Ltd. Minimum withdrawal €{{ number_format($minWithdrawal, 2) }}. No fees ever. Payout mode: {{ $mode }}.</p>
    <div class="partner-tiles">
        <div class="partner-tile">
            <strong>€{{ number_format($pending, 2) }}</strong>
            <span>Held</span>
        </div>
        <div class="partner-tile">
            <strong>€{{ number_format($available, 2) }}</strong>
            <span>Available to withdraw</span>
        </div>
        <div class="partner-tile">
            <strong>0%</strong>
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
                    <label>Amount (EUR)
                        <input type="number" name="amount" min="{{ $minWithdrawal }}" step="0.01" value="{{ old('amount') }}" required>
                    </label>
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

    @if ($mode !== 'instant')
        <div class="partner-card">
            <h2>Instant payouts</h2>
            @if ($keepLiveRequested)
                <p>Keep-live is stored. Waiting for SLB to approve instant payouts.</p>
            @else
                <p class="partner-lead">{{ $keepLiveText }}</p>
                <form method="post" action="{{ \App\Support\PartnerDashboard::route('money.instant') }}" class="partner-form">
                    @csrf
                    <label><input type="checkbox" name="accept_keep_live" value="1" required> I accept the keep-live promise</label>
                    <button class="partner-btn secondary" type="submit">Request instant payouts</button>
                </form>
            @endif
        </div>
    @endif

    <div class="partner-card">
        <h2>Ledger</h2>
        <table class="partner-table">
            <thead><tr><th>When</th><th>Type</th><th>Amount</th></tr></thead>
            <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td data-label="When">{{ $line['created_at'] }}</td>
                    <td data-label="Type">{{ str_replace('_', ' ', $line['type']) }}</td>
                    <td data-label="Amount">€{{ number_format($line['amount'] / 100, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No earnings yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="partner-card">
        <h2>Statements</h2>
        <table class="partner-table">
            <thead><tr><th>Period</th><th>Earned</th><th>Paid out</th></tr></thead>
            <tbody>
            @forelse ($statements as $row)
                <tr>
                    <td data-label="Period">{{ $row->period }}</td>
                    <td data-label="Earned">€{{ number_format((($row->totals['earned'] ?? 0) / 100), 2) }}</td>
                    <td data-label="Paid out">€{{ number_format((($row->totals['paid_out'] ?? 0) / 100), 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No statements yet. Monthly statements appear here.</td></tr>
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
                    <td data-label="Amount">€{{ number_format((float) $row->amount, 2) }}</td>
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
