{{-- Quiet ownership claim. Lives in Site Details so Buy stays the closed-row CTA.
     Expects $site, $displayName, $identityLabel, $canSeeUrl, $isOwnedByMe. --}}
@unless($isOwnedByMe)
    <button type="button"
            class="btn-claim-site"
            data-site-id="{{ $site->id }}"
            data-site-name="{{ $displayName }}"
            data-site-url="{{ $canSeeUrl ? $site->site_url : '' }}"
            data-glass-tip-placement="left"
            title="Is this your site? Claim it if you own it"
            aria-label="Claim website {{ $identityLabel }}">
        Is this your site?
    </button>
@endunless
