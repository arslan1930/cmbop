{{-- Report + one private reminder, same size as the social glyphs on this row. --}}
@php
    $siteToolNote = trim((string) (($catalogNotes ?? [])[$site->id] ?? ''));
    $siteToolNotePreview = $siteToolNote === ''
        ? 'One private reminder for this site. Only you can see it.'
        : \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $siteToolNote) ?? $siteToolNote, 140);
@endphp
<span class="catalog-site-tools">
    <button type="button"
            class="catalog-site-tool catalog-site-report"
            data-id="{{ $site->id }}"
            data-name="{{ $displayName }}"
            aria-label="Report {{ $identityLabel }}">
        <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
    </button>
    <button type="button"
            class="catalog-site-tool catalog-site-note {{ $siteToolNote !== '' ? 'is-active' : '' }}"
            data-id="{{ $site->id }}"
            data-name="{{ $displayName }}"
            data-note="{{ $siteToolNote }}"
            data-glass-tip
            data-glass-tip-hover-only="1"
            data-glass-tip-placement="top"
            data-glass-tip-title="{{ $siteToolNote !== '' ? 'Your reminder' : 'Add a reminder' }}"
            data-glass-tip-body="{{ $siteToolNotePreview }}"
            aria-label="{{ $siteToolNote !== '' ? 'Your reminder for '.$identityLabel : 'Add a reminder for '.$identityLabel }}">
        <i class="fa-solid fa-message" aria-hidden="true"></i>
    </button>
</span>
