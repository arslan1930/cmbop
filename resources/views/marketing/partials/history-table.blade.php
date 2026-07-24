@php
    $actionLabels = [
        'bulk_request.seeded' => 'Seeded / added sites',
        'bulk_request.sheet_sent' => 'Marked sheet sent',
        'bulk_request.cancelled' => 'Cancelled bulk request',
        'bulk_request.notes_updated' => 'Updated bulk notes',
        'site.deleted_by_marketing' => 'Deleted pending site',
        'site.updated' => 'Edited site',
        'site.image_uploaded' => 'Uploaded site image',
        'site.metrics_refreshed' => 'Refreshed metrics',
        'site.screenshot_refreshed' => 'Refreshed screenshot',
        'site.metrics_manual' => 'Saved manual metrics',
    ];
@endphp
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>When</th>
                <th>Task</th>
                <th>Subject</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="small text-nowrap">
                        {{ $log->created_at?->format('d M Y') }}<br>
                        <span class="text-muted">{{ $log->created_at?->format('H:i') }}</span>
                    </td>
                    <td>
                        <div class="fw-semibold">{{ $actionLabels[$log->action] ?? $log->action }}</div>
                    </td>
                    <td class="small">{{ $log->subject_label ?: '—' }}</td>
                    <td class="small">{{ $log->description }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">
                        No marketing tasks recorded yet. Seed sites or edit listings to build your history.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
