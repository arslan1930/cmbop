{{-- Catalog trust: score + rating count + completion %; last published on the next row. --}}
@php
    $avg = 0.0;
    $count = 0;
    $hasRatings = false;
    $completionRate = null;
    $lastPublished = null;
    $completedCount = 0;
    $showStars = false;
    $showCompletionRate = false;
    $ariaParts = ['Awaiting first ratings', 'No completed orders yet'];
    try {
        $avg = (float) ($site->rating_avg ?? 0);
        $count = (int) ($site->rating_count ?? 0);
        $hasRatings = $count >= 1;
        $completionRate = $site->completionRatePercent();
        $lastPublished = $site->lastPublicationLabel();
        $completedCount = (int) ($site->completionOutcomeCounts()['completed'] ?? 0);
        // Stars only after completed placements — leftover rating_count, or
        // cancelled-only history, must not look like a proven 5.0.
        $showStars = $hasRatings && $completedCount > 0;
        $showCompletionRate = $completionRate !== null && $completedCount > 0;
        $ariaParts = [];
        if ($showStars) {
            $ariaParts[] = number_format($avg, 1).' out of 5 from '.$count.' '.($count === 1 ? 'rating' : 'ratings');
        } else {
            $ariaParts[] = 'Awaiting first ratings';
        }
        if ($showCompletionRate) {
            $ariaParts[] = $completionRate.' percent completed';
        } else {
            $ariaParts[] = 'No completed orders yet';
        }
        if ($lastPublished) {
            $ariaParts[] = $lastPublished;
        }
    } catch (\Throwable $e) {
        report($e);
    }
    $trustVariant = ($variant ?? 'details') === 'chip' ? 'chip' : 'details';
@endphp
@if($trustVariant === 'chip')
    @if($showStars)
        <span class="site-trust-chip"
              data-site-id="{{ $site->id }}"
              title="{{ number_format($avg, 1) }} out of 5 from {{ $count }} {{ $count === 1 ? 'rating' : 'ratings' }} — full trust in Details">
            <i class="fa-solid fa-star" aria-hidden="true"></i>
            <span>{{ number_format($avg, 1) }}</span>
        </span>
    @endif
@else
<div class="site-trust-compact {{ $compactClass ?? 'mt-2' }}"
     data-site-id="{{ $site->id }}"
     role="group"
     aria-label="{{ implode('. ', $ariaParts) }}">
    <div class="site-trust-compact__row">
        @if($showStars)
            <span class="site-trust-compact__stars" aria-hidden="true">
                @for($i = 1; $i <= 5; $i++)
                    @php
                        $threshold = (float) $i;
                        $halfThreshold = $threshold - 0.5;
                        if ($avg >= $threshold) {
                            $starClass = 'fa-solid fa-star';
                        } elseif ($avg >= $halfThreshold) {
                            $starClass = 'fa-solid fa-star-half-stroke';
                        } else {
                            $starClass = 'fa-regular fa-star';
                        }
                    @endphp
                    <i class="{{ $starClass }}" aria-hidden="true"></i>
                @endfor
                <span class="site-trust-compact__score">{{ number_format($avg, 1) }}</span>
                <span class="site-trust-compact__count">· {{ $count }} {{ $count === 1 ? 'rating' : 'ratings' }}</span>
            </span>
        @else
            <span class="site-trust-compact__empty">Awaiting first ratings</span>
        @endif

        <span class="site-trust-compact__sep" aria-hidden="true">·</span>

        @if($showCompletionRate)
            <span class="site-trust-compact__rate">{{ $completionRate }}% completed</span>
        @else
            <span class="site-trust-compact__rate site-trust-compact__rate--empty">No completed orders yet</span>
        @endif
    </div>

    @if($lastPublished)
        <div class="site-trust-compact__published">
            <i class="fa-regular fa-clock" aria-hidden="true"></i>
            <span>{{ $lastPublished }}</span>
        </div>
    @endif
</div>
@endif
