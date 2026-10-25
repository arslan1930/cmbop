@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
        <div>
            <h2 class="mb-1 fw-semibold">Abandoned carts</h2>
            <p class="text-muted mb-0">Advertisers with saved cart items. Reminders stay off until you enable them.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.emails.index') }}" class="btn btn-outline-secondary btn-sm">Email Center</a>
            <a href="{{ route('admin.campaigns.index') }}" class="btn btn-outline-secondary btn-sm">Campaigns</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Saved carts</div>
                    <div class="fs-4 fw-semibold">{{ number_format($counts['carts']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Saved items</div>
                    <div class="fs-4 fw-semibold">{{ number_format($counts['items']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Stage 1 sent ({{ $stage1Hours }}h)</div>
                    <div class="fs-4 fw-semibold">{{ number_format($counts['stage_1']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Stage 2 sent ({{ $stage2Hours }}h)</div>
                    <div class="fs-4 fw-semibold">{{ number_format($counts['stage_2']) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h3 class="h6 mb-1">Master switch</h3>
                <p class="text-muted small mb-0">
                    @if(!empty($enabled))
                        Reminders can send on the hourly schedule ({{ $stage1Hours }}h / {{ $stage2Hours }}h). Marketing opt-out, daily cap, and unverified or blocked accounts are still respected.
                    @else
                        Nothing is sent. Enable here or set <code>ABANDONED_CART_REMINDERS_ENABLED=true</code>, then enable here if you want the DB toggle to win.
                    @endif
                </p>
            </div>
            @if(!empty($settingsReady))
                <form method="POST" action="{{ route('admin.abandoned-carts.toggle') }}"
                      @if(!empty($enabled))
                          data-slb-confirm="Turn off abandoned cart reminders? No further 24h / 72h emails will be sent until you enable them again."
                      @endif>
                    @csrf
                    <input type="hidden" name="enabled" value="{{ !empty($enabled) ? '0' : '1' }}">
                    <button type="submit" class="btn btn-sm {{ !empty($enabled) ? 'btn-outline-danger' : 'btn-primary' }}">
                        {{ !empty($enabled) ? 'Disable' : 'Enable' }}
                    </button>
                </form>
            @else
                <span class="text-muted small">Run the SQL/migration to enable the toggle.</span>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h3 class="h6 mb-3">Carts with saved items</h3>
            @if($rows->isEmpty())
                <p class="text-muted mb-0">No saved advertiser carts yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Advertiser</th>
                                <th>Sites</th>
                                <th>Placements</th>
                                <th>Last activity</th>
                                <th>Reminders sent</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td>
                                        @if($row['user'])
                                            <div>{{ scalar_text($row['user']->name) }}</div>
                                            <div class="small text-muted">{{ scalar_text($row['user']->email) }}</div>
                                        @else
                                            <span class="text-muted">Account removed</span>
                                        @endif
                                    </td>
                                    <td>{{ (int) $row['item_count'] }}</td>
                                    <td>{{ (int) $row['placement_count'] }}</td>
                                    <td>{{ $row['last_activity_at'] ? \Illuminate\Support\Carbon::parse($row['last_activity_at'])->format('Y-m-d H:i') : '—' }}</td>
                                    <td>Stage 1: {{ (int) $row['stage_1_count'] }} · Stage 2: {{ (int) $row['stage_2_count'] }}</td>
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
