{{-- Full sample-article URL (no ellipsis) plus copy / open. Expects $site, $identityLabel, $sampleVisit. --}}
@php
    $rawSample = trim((string) ($site->example_url ?? ''));
    $sampleDisplay = $rawSample;
    $parts = parse_url($rawSample);
    if (! empty($parts['host'])) {
        $sampleDisplay = $parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
@endphp
<div class="catalog-sample-url-row">
    <a href="{{ $sampleVisit }}"
       target="_blank"
       rel="noopener noreferrer"
       class="text-decoration-none catalog-site-url catalog-sample-url"
       title="{{ $rawSample }}">{{ $sampleDisplay }}</a>
    <button type="button"
            class="btn btn-link p-0 copy-example-url catalog-sample-copy"
            data-url="{{ $sampleVisit }}"
            data-site-id="{{ $site->id }}"
            data-glass-tip
            data-glass-tip-title="Copy sample article URL"
            data-glass-tip-body="Copies the marketplace sample link."
            data-glass-tip-placement="top"
            aria-label="Copy the sample article URL for {{ $identityLabel }}">
        <i class="fa-regular fa-copy" aria-hidden="true"></i>
    </button>
    <a href="{{ $sampleVisit }}"
       target="_blank"
       rel="noopener noreferrer"
       class="text-muted d-inline-flex align-items-center catalog-sample-open"
       data-glass-tip
       data-glass-tip-title="Open sample article"
       data-glass-tip-body="Opens this publisher’s example placement in a new tab."
       data-glass-tip-placement="top"
       title="Open sample article"
       aria-label="Open the sample article for {{ $identityLabel }} in a new tab">
        <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
    </a>
</div>
