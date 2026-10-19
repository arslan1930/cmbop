@extends('partner.layouts.app')
@section('title', 'My sites')
@section('content')
    <h1 class="partner-h1">My sites</h1>
    <p class="partner-lead">Your rate only. Sites that are not live show as “Not listed” with no further reason.</p>
    <div class="partner-card">
        <table class="partner-table">
            <thead>
            <tr>
                <th>Site</th>
                <th>Status</th>
                <th>Your rate</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Site">{{ $row['domain'] }}</td>
                    <td data-label="Status"><span class="partner-badge {{ $row['status'] === 'live' ? 'is-ok' : ($row['status'] === 'paused' ? 'is-warn' : '') }}">{{ $row['status_label'] }}</span></td>
                    <td data-label="Your rate">{{ $row['your_rate'] }}@if($row['original_rate']) <span class="partner-lead">({{ $row['original_rate'] }})</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="3">No imported sites yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if(method_exists($paginator, 'links'))
            <div class="partner-pager">{{ $paginator->links() }}</div>
        @endif
    </div>
@endsection
