@php
    $decisionRaw = $reasonText ?? '';
    $decisionArchived = ! empty($archived);
    $decisionFull = \App\Support\StaffDecisionNote::plain($decisionRaw);
    $decisionSummary = \App\Support\StaffDecisionNote::summary($decisionRaw, $decisionArchived);
    $decisionExpand = \App\Support\StaffDecisionNote::expandable($decisionRaw, $decisionArchived);
    $decisionWho = trim((string) ($reasonWho ?? ''));
    $decisionWhen = trim((string) ($reasonWhen ?? ''));
@endphp
@if($decisionSummary !== '')
    <div class="staff-decision-note small text-muted mt-1">
        <div class="staff-decision-note__summary">
            Last decision: {{ $decisionSummary }}
            @if($decisionWho !== '')
                · {{ $decisionWho }}
            @endif
            @if($decisionWhen !== '')
                · {{ $decisionWhen }}
            @endif
        </div>
        @if($decisionExpand)
            <details class="staff-decision-note__more">
                <summary>View message</summary>
                <div class="staff-decision-note__full">{{ $decisionFull }}</div>
            </details>
        @endif
    </div>
@endif
