@php
    use App\Support\CommunityInbox;
    $pageUrl = $pageUrl ?? CommunityInbox::safeHttpUrl($item->page_url ?? $item->website_url ?? null);
    $ctx = $ctx ?? [];
    $siblings = (int) ($siblings ?? 0);
    $catalog = $catalog ?? null;
@endphp
<template id="community-detail-{{ $tab }}-{{ $item->id }}">
    <dl class="row mb-0 small">
        @if($tab === 'problems' || $tab === 'suggestions')
            <dt class="col-12">From</dt>
            <dd class="col-12">
                {{ $item->name ?: ($item->user?->name ?? '—') }}
                @if($item->email || $item->user?->email)
                    <div class="text-muted">{{ $item->email ?: $item->user?->email }}</div>
                @endif
                @if($item->user_id)
                    <a href="{{ route('admin.users.show', $item->user_id) }}">Open user</a>
                @endif
            </dd>
            @if($tab === 'problems')
                <dt class="col-12">Subject</dt>
                <dd class="col-12">{{ $item->subject }}</dd>
            @else
                <dt class="col-12">Category</dt>
                <dd class="col-12">{{ $item->category }}</dd>
            @endif
            <dt class="col-12">{{ $tab === 'problems' ? 'Message' : 'Suggestion' }}</dt>
            <dd class="col-12" style="white-space:pre-wrap;">{{ $tab === 'problems' && is_array($catalog) && ($catalog['user_message'] ?? '') !== '' ? $catalog['user_message'] : $item->message }}</dd>
            @if($tab === 'problems' && is_array($catalog))
                <dt class="col-12">Listing</dt>
                <dd class="col-12">
                    @if(! empty($catalog['site']))
                        <div>{{ $catalog['site']->site_name ?: $catalog['site']->domain }}</div>
                        @if($catalog['site']->domain)
                            <div class="text-muted">{{ $catalog['site']->domain }}</div>
                        @endif
                        @if(! empty($catalog['listing_url']))
                            <a href="{{ $catalog['listing_url'] }}">Open listing</a>
                        @endif
                        @if(! empty($catalog['edit_url']))
                            <div><a href="{{ $catalog['edit_url'] }}">Edit in admin</a></div>
                        @endif
                    @elseif(! empty($catalog['site_id']))
                        <div>Listing no longer in the catalog.</div>
                    @else
                        —
                    @endif
                </dd>
            @endif
            <dt class="col-12">Page</dt>
            <dd class="col-12">
                @if($pageUrl)
                    <a href="{{ $pageUrl }}" target="_blank" rel="noopener noreferrer">{{ $pageUrl }}</a>
                @else
                    —
                @endif
            </dd>
            @if($tab === 'problems' && is_array($catalog) && (string) $item->message !== '')
                <dt class="col-12">Raw report</dt>
                <dd class="col-12">
                    <details>
                        <summary>Show full envelope</summary>
                        <div class="mt-2" style="white-space:pre-wrap;">{{ $item->message }}</div>
                    </details>
                </dd>
            @endif
        @elseif($tab === 'websites')
            <dt class="col-12">Website</dt>
            <dd class="col-12">
                <div>{{ $item->website_name }}</div>
                @if($pageUrl)
                    <a href="{{ $pageUrl }}" target="_blank" rel="noopener noreferrer">{{ $item->website_url }}</a>
                @else
                    {{ $item->website_url }}
                @endif
                <div class="text-muted">{{ $item->domain }}</div>
            </dd>
            <dt class="col-12">Market</dt>
            <dd class="col-12">{{ $item->country ?: '—' }} / {{ $item->language ?: '—' }}</dd>
            <dt class="col-12">Requested by</dt>
            <dd class="col-12">
                {{ $item->user?->name ?? '—' }}
                <div class="text-muted">{{ $item->user?->email ?? '' }}</div>
                @if($item->user_id)
                    <a href="{{ route('admin.users.show', $item->user_id) }}">Open user</a>
                @endif
            </dd>
            <dt class="col-12">Search</dt>
            <dd class="col-12">{{ $item->search_query ?: '—' }}</dd>
            <dt class="col-12">Notes</dt>
            <dd class="col-12" style="white-space:pre-wrap;">{{ $item->notes ?: '—' }}</dd>
            <dt class="col-12">Listing</dt>
            <dd class="col-12">
                @include('admin.community.website-listing-action', ['item' => $item])
                @php $occupyingSite = ($occupyingSites ?? [])[$item->id] ?? null; @endphp
                @if($occupyingSite)
                    <div><a href="{{ staff_route('sites.edit', $occupyingSite->id) }}">Edit in admin</a></div>
                @endif
            </dd>
        @else
            <dt class="col-12">Listing</dt>
            <dd class="col-12">
                {{ $item->site?->site_name ?? $item->website_name }}
                <div class="text-muted">{{ $item->domain }}</div>
                @if($item->site_id)
                    @if($item->site)
                        <a href="{{ \App\Support\CatalogProblemReport::staffListingUrl($item->site) }}">Open listing</a>
                    @endif
                    <div><a href="{{ staff_route('sites.edit', $item->site_id) }}">Edit in admin</a></div>
                @endif
            </dd>
            <dt class="col-12">Provided name</dt>
            <dd class="col-12">{{ $item->website_name }}</dd>
            <dt class="col-12">Claimer</dt>
            <dd class="col-12">
                {{ $item->claimer?->name ?? '—' }}
                <div class="text-muted">{{ $item->contact_email ?: ($item->claimer?->email ?? '') }}</div>
                @if($item->claimer_id)
                    <a href="{{ route('admin.users.show', $item->claimer_id) }}">Open user</a>
                @endif
                <div>{{ !empty($ctx['claimer_has_publisher_role']) ? 'Has publisher role' : 'No publisher role yet' }}</div>
            </dd>
            <dt class="col-12">Current owner</dt>
            <dd class="col-12">
                {{ $item->site?->publisher?->name ?? '—' }}
                <div class="text-muted">{{ $item->site?->publisher?->email ?? '' }}</div>
                @if($item->site?->publisher_id)
                    <a href="{{ route('admin.users.show', $item->site->publisher_id) }}">Open user</a>
                @endif
            </dd>
            <dt class="col-12">Verification</dt>
            <dd class="col-12">
                {{ $item->name_matches ? 'Name matches the listing' : 'Name does not match' }}
                <div>{{ !empty($ctx['verified']) ? 'Listing is verified' : 'Listing is not verified' }}</div>
                <div>{{ (int) ($ctx['open_orders'] ?? 0) }} open order(s), {{ (int) ($ctx['open_disputes'] ?? 0) }} open dispute(s)</div>
                @if($siblings > 0)
                    <div>{{ $siblings }} other pending claim(s) on this site</div>
                @endif
            </dd>
            <dt class="col-12">Proof</dt>
            <dd class="col-12" style="white-space:pre-wrap;">{{ $item->proof_message }}</dd>
        @endif
        <dt class="col-12">Status</dt>
        <dd class="col-12">{{ $item->status }}</dd>
        <dt class="col-12">Admin notes</dt>
        <dd class="col-12" style="white-space:pre-wrap;">{{ $item->admin_notes ?: '—' }}</dd>
        <dt class="col-12">Reviewer</dt>
        <dd class="col-12">
            {{ $item->reviewer?->name ?? '—' }}
            @if($item->reviewed_at)
                <div class="text-muted">{{ $item->reviewed_at->diffForHumans() }}</div>
            @endif
        </dd>
        <dt class="col-12">Submitted</dt>
        <dd class="col-12">{{ optional($item->created_at)->toDayDateTimeString() ?: '—' }}</dd>
    </dl>
</template>
