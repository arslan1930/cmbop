@php
    $listedAt = $site->created_at ?? null;
    $listedDays = null;
    $listedTitle = '';
    if ($listedAt instanceof \DateTimeInterface) {
        $listedDays = (int) \Illuminate\Support\Carbon::parse($listedAt)->diffInDays(now());
        $listedTitle = 'Listed '.\Illuminate\Support\Carbon::parse($listedAt)->timezone(config('app.timezone'))->format('M j, Y');
    }
    $listedClass = 'text-muted';
    if ($listedDays !== null && $listedDays >= 21) {
        $listedClass = 'text-danger fw-semibold';
    } elseif ($listedDays !== null && $listedDays >= 7) {
        $listedClass = 'text-warning fw-semibold';
    }
@endphp
@if($listedDays === null)
    <span class="text-muted">—</span>
@else
    <span class="{{ $listedClass }}" title="{{ $listedTitle }}">{{ $listedDays === 0 ? 'Today' : $listedDays.'d' }}</span>
@endif
