@php
    $isMarketingEditor = (bool) (auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin());
    $hasOrders = $site->orderItemsCount() > 0;
    $canRestore = (bool) auth()->user()?->isAdmin() && $site->isArchived();
    $canArchive = (bool) auth()->user()?->isAdmin()
        && ! $site->isArchived()
        && ! $hasOrders
        && ($site->verified || $site->active || $site->wasAddedByPublisher() || $site->isBulkRequestDraft());
    $canReject = ! $site->isArchived()
        && ! $hasOrders
        && ! $site->verified
        && ! $site->active
        && (auth()->user()?->isAdmin() || $isMarketingEditor);
@endphp
@if($canRestore)
    <button type="button"
            class="btn btn-sm btn-outline-secondary unarchive-site staff-action-icon-btn"
            data-id="{{ $site->id }}"
            data-name="{{ $site->site_name }}"
            title="Restore"
            aria-label="Restore">
        <i class="fa fa-undo" aria-hidden="true"></i>
    </button>
@else
    @if($canReject)
        <button type="button"
                class="btn btn-sm btn-outline-danger delete-site staff-action-icon-btn"
                data-id="{{ $site->id }}"
                data-name="{{ $site->site_name }}"
                title="Reject"
                aria-label="Reject">
            <i class="fa fa-times" aria-hidden="true"></i>
        </button>
    @endif
    @if($canArchive)
        <button type="button"
                class="btn btn-sm btn-outline-secondary archive-site staff-action-icon-btn"
                data-id="{{ $site->id }}"
                data-name="{{ $site->site_name }}"
                @if($site->wasAddedByPublisher()) data-publisher-added="1" @endif
                @if($site->isBulkRequestDraft()) data-bulk-draft="1" @endif
                title="Archive"
                aria-label="Archive">
            <i class="fa fa-archive" aria-hidden="true"></i>
        </button>
    @endif
@endif
