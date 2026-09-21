{{-- Quiet ownership claim. Lives in Site Details so Buy stays the closed-row CTA.
     Expects $site, $displayName, $identityLabel, $canSeeUrl, $isOwnedByMe. --}}
@php
    $claimUrl = '';
    try {
        if (! empty($canSeeUrl) && isset($site)) {
            $rawClaimUrl = method_exists($site, 'leftoverStringAttribute')
                ? (string) ($site->leftoverStringAttribute('site_url') ?? '')
                : trim((string) ($site->site_url ?? ''));
            $claimUrl = ($rawClaimUrl !== '' && safe_external_url($rawClaimUrl) !== '#')
                ? $rawClaimUrl
                : '';
        }
    } catch (\Throwable $e) {
        report($e);
        $claimUrl = '';
    }
@endphp
@unless(! empty($isOwnedByMe))
    <button type="button"
            class="btn-claim-site"
            data-site-id="{{ $site->id ?? '' }}"
            data-site-name="{{ $displayName ?? '' }}"
            data-site-url="{{ $claimUrl }}"
            data-glass-tip-placement="left"
            title="Is this your site? Claim it if you own it"
            aria-label="Claim website {{ $identityLabel ?? 'this website' }}">
        Is this your site?
    </button>
@endunless
