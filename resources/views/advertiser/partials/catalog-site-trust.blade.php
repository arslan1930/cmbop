{{-- Catalog trust strip: score + rating count + completion rate (no comments).
     Ratings: one per completed order line. Rate only from Orders after approve. --}}
@php
    $avg = (float) ($site->rating_avg ?? 0);
    $count = (int) ($site->rating_count ?? 0);
    $hasRatings = $count >= 1;
    $completionRate = $site->completionRatePercent();
    $completedCount = (int) ($site->completionOutcomeCounts()['completed'] ?? 0);
    // Stars only after completed placements — leftover rating_count, or
    // cancelled-only history, must not look like a proven 5.0.
    $showStars = $hasRatings && $completedCount > 0;
    $staffReviewed = $site->isStaffReviewedListing();
    $ariaParts = [];
    if ($showStars) {
        $ariaParts[] = number_format($avg, 1).' out of 5 from '.$count.' '.($count === 1 ? 'rating' : 'ratings');
    } else {
        $ariaParts[] = 'Awaiting first ratings';
        if ($staffReviewed) {
            $ariaParts[] = 'Staff reviewed listing';
        }
    }
    if ($completionRate !== null) {
        $ariaParts[] = $completionRate.' percent completed';
    } else {
        $ariaParts[] = 'No completed orders yet';
    }
@endphp
<div class="site-trust-compact {{ $compactClass ?? 'mt-2' }}"
     data-site-id="{{ $site->id }}"
     data-glass-tip
     data-glass-tip-title="Publisher trust"
     data-glass-tip-body="{{ $showStars
        ? 'Ratings from advertisers after completed orders. Completion rate is successful vs cancelled placements on this site.'
        : ($staffReviewed
            ? 'Staff reviewed this listing. Ratings appear after advertisers complete orders — new listings start at zero. We do not show placeholder stars.'
            : 'Ratings from advertisers after completed orders. Completion rate is successful vs cancelled placements on this site.') }}"
     data-glass-tip-placement="top"
     role="group"
     aria-label="{{ implode('. ', $ariaParts) }}">
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

    @if($completionRate !== null)
        <span class="site-trust-compact__rate">{{ $completionRate }}% completed</span>
    @else
        <span class="site-trust-compact__rate site-trust-compact__rate--empty">No completed orders yet</span>
    @endif
</div>
