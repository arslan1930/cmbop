@php
    $state = $item->scheduleState();
    $showingState = $showingState ?? null;
@endphp
@if($state === 'live')
    <span class="badge bg-success">Live</span>
@elseif($state === 'scheduled')
    <span class="badge bg-warning text-dark">Scheduled</span>
@elseif($state === 'expired')
    <span class="badge bg-danger-subtle text-danger">Expired</span>
@else
    <span class="badge bg-secondary">Paused</span>
@endif
@if($showingState === 'showing')
    <span class="badge bg-primary">Showing</span>
@elseif($showingState === 'queued')
    <span class="badge bg-warning text-dark">Live, not shown</span>
@elseif($showingState === 'rotated_out')
    <span class="badge bg-warning text-dark">Not today’s rotation</span>
@endif
