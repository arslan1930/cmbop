@php
    $state = $item->scheduleState();
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
