{{-- Report + one private note, same size as the social glyphs on this row. --}}
@php
    $siteToolNote = trim((string) (($catalogNotes ?? [])[$site->id] ?? ''));
    $siteToolReported = ! empty($catalogReported) && in_array((int) $site->id, $catalogReported, true);
    $siteToolReport = trim((string) (($catalogReports ?? [])[$site->id] ?? ''));
    $siteToolHost = trim((string) ($displayHost ?? ''));
    if ($siteToolHost === '') {
        $siteToolHost = trim((string) ($site->domain ?? ''));
    }
    if ($siteToolHost === '') {
        $siteToolHost = trim((string) ($displayName ?? 'this site'));
    }
@endphp
<span class="catalog-site-tools">
    <button type="button"
            class="catalog-site-tool catalog-site-report {{ $siteToolReported ? 'is-active' : '' }}"
            data-id="{{ $site->id }}"
            data-name="{{ $siteToolHost }}"
            data-report="{{ $siteToolReport }}"
            data-no-tip
            aria-label="{{ $siteToolReported ? 'You reported '.$identityLabel : 'Report '.$identityLabel }}">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    </button>
    <button type="button"
            class="catalog-site-tool catalog-site-note {{ $siteToolNote !== '' ? 'is-active' : '' }}"
            data-id="{{ $site->id }}"
            data-name="{{ $siteToolHost }}"
            data-note="{{ $siteToolNote }}"
            data-no-tip
            aria-label="{{ $siteToolNote !== '' ? 'Your notes for '.$identityLabel : 'Notes for '.$identityLabel }}">
        <i class="fa-solid fa-sticky-notes" aria-hidden="true"></i>
    </button>
</span>
