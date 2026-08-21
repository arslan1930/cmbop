{{--
    Verify / Activate controls for the staff site editor.

    @param \App\Models\Site $site
    @param bool|null $isMarketingEditor
--}}
@php
    $actor = auth()->user();
    $isMarketingEditor = $isMarketingEditor ?? (bool) ($actor?->isMarketing() && ! $actor?->isAdmin());
    $canVerify = (bool) $actor?->isAdmin();
    $canActivate = (bool) $actor?->canActivateSites();
    $activateBlock = $site->staffGoLiveBlockReason($isMarketingEditor);
    $canGoLive = $activateBlock === null;
@endphp
<div class="staff-site-status-actions"
     data-staff-site-status
     data-staff-base="{{ rtrim(staff_base_path(), '/') }}">
    <label class="form-label fw-semibold d-block">Status</label>
    <div class="d-flex flex-wrap gap-2">
        <span class="badge {{ $site->verified ? 'bg-success' : 'bg-warning text-dark' }}">
            {{ $site->verified ? 'Verified' : 'Unverified' }}
        </span>
        <span class="badge {{ $site->active ? 'bg-primary' : 'bg-secondary' }}">
            {{ $site->active ? 'Active' : 'Inactive' }}
        </span>
    </div>
    @if($canVerify || $canActivate)
        <div class="d-flex flex-wrap gap-2 mt-2">
            @if($canVerify)
                @if($site->verified)
                    <button type="button"
                            class="btn btn-sm btn-outline-warning js-staff-verify"
                            data-id="{{ $site->id }}"
                            data-verified="0">
                        Unverify
                    </button>
                @else
                    <button type="button"
                            class="btn btn-sm btn-outline-success js-staff-verify"
                            data-id="{{ $site->id }}"
                            data-verified="1">
                        Verify
                    </button>
                @endif
            @endif
            @if($canActivate)
                @if($site->active)
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary js-staff-deactivate"
                            data-id="{{ $site->id }}"
                            data-name="{{ $site->site_name }}">
                        Deactivate
                    </button>
                @elseif($canGoLive)
                    <button type="button"
                            class="btn btn-sm btn-success js-staff-activate"
                            data-id="{{ $site->id }}"
                            data-name="{{ $site->site_name }}"
                            data-description-english="{{ $site->descriptionLooksLikeEnglish() ? '1' : '0' }}"
                            data-description-excerpt="{{ site_description_excerpt($site->description, 200) }}">
                        Activate
                    </button>
                @else
                    <button type="button"
                            class="btn btn-sm btn-success js-staff-activate-blocked"
                            disabled
                            title="{{ $activateBlock }}">
                        Activate
                    </button>
                @endif
            @endif
        </div>
    @endif
    @if($canActivate && ! $site->active && $activateBlock)
        <div class="form-text mt-2">{{ $activateBlock }}</div>
    @elseif($canVerify || $canActivate)
        <div class="form-text mt-2">Save does not change status. Use Verify or Activate here.</div>
    @endif
</div>
@once
    {{-- Inline (not @push) so view()->file('admin/site-edit') still binds Verify / Activate. --}}
    <script src="{{ asset('assets/js/staff-site-status.js') }}?v={{ @filemtime(public_path('assets/js/staff-site-status.js')) ?: '1' }}"></script>
@endonce
