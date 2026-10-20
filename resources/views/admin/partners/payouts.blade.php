@extends(staff_layout())

@section('title', 'Partner payouts')

@section('content')
<div class="container-fluid py-3">
    <h4 class="fw-bold mb-1">Partner payouts</h4>
    <p class="text-muted small">Held vs available from the partner ledger. Instant needs a stored keep-live acceptance. Exposure cap default €500.</p>
    <div class="table-responsive card border-0 shadow-sm mb-4">
        <table class="table table-sm mb-0">
            <thead>
            <tr>
                <th>Partner</th>
                <th>Mode</th>
                <th>Held</th>
                <th>Available</th>
                <th>Instant exposure</th>
                <th>Keep-live</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td><a href="{{ route('admin.partners.show', $row['partner']) }}">{{ $row['partner']->displayName() }}</a></td>
                    <td>{{ $row['mode'] }}</td>
                    <td>€{{ number_format($row['pending'] / 100, 2) }}</td>
                    <td>€{{ number_format($row['available'] / 100, 2) }}</td>
                    <td>€{{ number_format($row['exposure'] / 100, 2) }} / €{{ number_format($row['cap'] / 100, 2) }}</td>
                    <td>{{ $row['keep_live'] ? 'yes' : 'no' }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.partners.payout-mode', $row['partner']) }}" class="d-flex gap-1">
                            @csrf
                            <select name="mode" class="form-select form-select-sm">
                                <option value="held" @selected($row['mode'] === 'held')>held</option>
                                <option value="instant" @selected($row['mode'] === 'instant')>instant</option>
                            </select>
                            <input class="form-control form-control-sm" name="reason" placeholder="Reason" required>
                            <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h5>Clawbacks</h5>
            <table class="table table-sm">
                <thead><tr><th>Partner</th><th>Amount</th><th>Reason</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($clawbacks as $row)
                    <tr>
                        <td>{{ $row->partner?->displayName() }}</td>
                        <td>€{{ number_format($row->amount_minor / 100, 2) }}</td>
                        <td>{{ $row->reason }}</td>
                        <td>{{ $row->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">None.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
