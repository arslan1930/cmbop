@php
    $historyLookup = \App\Support\MarketingHistoryDisplay::preload($logs);
@endphp
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>When</th>
                <th>Task</th>
                <th>Subject</th>
                <th>Publisher</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                @php
                    $subjectUrl = marketing_history_subject_url($log, $historyLookup);
                    $bulkUrl = marketing_history_bulk_url($log, $historyLookup);
                    $publisherLabel = \App\Support\MarketingHistoryDisplay::publisherLabel($log, $historyLookup);
                    $reason = \App\Support\MarketingHistoryDisplay::reason($log);
                    $changeKeys = \App\Support\MarketingHistoryDisplay::changeKeys($log);
                    $removed = \App\Support\MarketingHistoryDisplay::isRemoved($log, $historyLookup);
                @endphp
                <tr>
                    <td class="small text-nowrap">
                        <div>{{ $log->created_at?->diffForHumans() }}</div>
                        <span class="text-muted">{{ $log->created_at?->format('d M Y H:i') }}</span>
                    </td>
                    <td>
                        <div class="fw-semibold">{{ marketing_task_label($log->action) }}</div>
                    </td>
                    <td class="small">
                        @if($subjectUrl)
                            <a href="{{ $subjectUrl }}">{{ $log->subject_label ?: 'Open' }}</a>
                        @else
                            {{ $log->subject_label ?: '—' }}
                        @endif
                        @if($removed)
                            <span class="badge bg-secondary ms-1" data-history-removed>Removed</span>
                        @endif
                        @if($bulkUrl)
                            <div>
                                <a href="{{ $bulkUrl }}">Bulk request</a>
                            </div>
                        @endif
                    </td>
                    <td class="small">{{ $publisherLabel ?: '—' }}</td>
                    <td class="small">
                        <div>{{ $log->description }}</div>
                        @if($reason)
                            <div class="text-muted mt-1" data-history-reason>Reason: {{ $reason }}</div>
                        @endif
                        @if($changeKeys !== [])
                            <div class="text-muted mt-1" data-history-changes>Changed: {{ implode(', ', $changeKeys) }}</div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        @if(!empty($filtersActive))
                            <div class="mb-2">No tasks match these filters.</div>
                            <a href="{{ route('marketing.history') }}" class="btn btn-sm btn-outline-secondary">Reset filters</a>
                        @else
                            <div class="mb-2">No marketing tasks recorded yet. Seed sites or edit listings to build your history.</div>
                            <a href="{{ route('marketing.sites.create') }}" class="btn btn-sm btn-outline-primary">Add site for publisher</a>
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
