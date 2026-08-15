@php
    use App\Support\CommunityInbox;
    $pageUrl = $pageUrl ?? CommunityInbox::safeHttpUrl($item->page_url ?? $item->website_url ?? null);
    $ctx = $ctx ?? [];
    $siblings = (int) ($siblings ?? 0);
@endphp
<template id="community-detail-{{ $tab }}-{{ $item->id }}">
    <dl class="row mb-0 small">
        @if($tab === 'problems' || $tab === 'suggestions')
            <dt class="col-sm-4">From</dt>
            <dd class="col-sm-8">
                {{ $item->name ?: ($item->user?->name ?? '—') }}
                @if($item->email || $item->user?->email)
                    <div class="text-muted">{{ $item->email ?: $item->user?->email }}</div>
                @endif
                @if($item->user_id)
                    <a href="{{ route('admin.users.index', ['user' => $item->user_id]) }}#user-{{ $item->user_id }}">Open user</a>
                @endif
            </dd>
            @if($tab === 'problems')
                <dt class="col-sm-4">Subject</dt>
                <dd class="col-sm-8">{{ $item->subject }}</dd>
            @else
                <dt class="col-sm-4">Category</dt>
                <dd class="col-sm-8">{{ $item->category }}</dd>
            @endif
            <dt class="col-sm-4">{{ $tab === 'problems' ? 'Message' : 'Suggestion' }}</dt>
            <dd class="col-sm-8" style="white-space:pre-wrap;">{{ $item->message }}</dd>
            <dt class="col-sm-4">Page</dt>
            <dd class="col-sm-8">
                @if($pageUrl)
                    <a href="{{ $pageUrl }}" target="_blank" rel="noopener noreferrer">{{ $pageUrl }}</a>
                @else
                    —
                @endif
            </dd>
        @elseif($tab === 'websites')
            <dt class="col-sm-4">Website</dt>
            <dd class="col-sm-8">
                <div>{{ $item->website_name }}</div>
                @if($pageUrl)
                    <a href="{{ $pageUrl }}" target="_blank" rel="noopener noreferrer">{{ $item->website_url }}</a>
                @else
                    {{ $item->website_url }}
                @endif
                <div class="text-muted">{{ $item->domain }}</div>
            </dd>
            <dt class="col-sm-4">Market</dt>
            <dd class="col-sm-8">{{ $item->country ?: '—' }} / {{ $item->language ?: '—' }}</dd>
            <dt class="col-sm-4">Requested by</dt>
            <dd class="col-sm-8">
                {{ $item->user?->name ?? '—' }}
                <div class="text-muted">{{ $item->user?->email ?? '' }}</div>
                @if($item->user_id)
                    <a href="{{ route('admin.users.index', ['user' => $item->user_id]) }}#user-{{ $item->user_id }}">Open user</a>
                @endif
            </dd>
            <dt class="col-sm-4">Search</dt>
            <dd class="col-sm-8">{{ $item->search_query ?: '—' }}</dd>
            <dt class="col-sm-4">Notes</dt>
            <dd class="col-sm-8" style="white-space:pre-wrap;">{{ $item->notes ?: '—' }}</dd>
            <dt class="col-sm-4">Listing</dt>
            <dd class="col-sm-8">@include('admin.community.website-listing-action', ['item' => $item])</dd>
        @else
            <dt class="col-sm-4">Listing</dt>
            <dd class="col-sm-8">
                {{ $item->site->site_name ?? $item->website_name }}
                <div class="text-muted">{{ $item->domain }}</div>
                @if($item->site_id)
                    <a href="{{ route('admin.sites.edit', $item->site_id) }}">Open listing</a>
                @endif
            </dd>
            <dt class="col-sm-4">Provided name</dt>
            <dd class="col-sm-8">{{ $item->website_name }}</dd>
            <dt class="col-sm-4">Claimer</dt>
            <dd class="col-sm-8">
                {{ $item->claimer?->name ?? '—' }}
                <div class="text-muted">{{ $item->contact_email ?: ($item->claimer?->email ?? '') }}</div>
                @if($item->claimer_id)
                    <a href="{{ route('admin.users.index', ['user' => $item->claimer_id]) }}#user-{{ $item->claimer_id }}">Open user</a>
                @endif
                <div>{{ !empty($ctx['claimer_has_publisher_role']) ? 'Has publisher role' : 'No publisher role yet' }}</div>
            </dd>
            <dt class="col-sm-4">Current owner</dt>
            <dd class="col-sm-8">
                {{ $item->site->publisher?->name ?? '—' }}
                <div class="text-muted">{{ $item->site->publisher?->email ?? '' }}</div>
                @if($item->site?->publisher_id)
                    <a href="{{ route('admin.users.index', ['user' => $item->site->publisher_id]) }}#user-{{ $item->site->publisher_id }}">Open user</a>
                @endif
            </dd>
            <dt class="col-sm-4">Verification</dt>
            <dd class="col-sm-8">
                {{ $item->name_matches ? 'Name matches the listing' : 'Name does not match' }}
                <div>{{ !empty($ctx['verified']) ? 'Listing is verified' : 'Listing is not verified' }}</div>
                <div>{{ (int) ($ctx['open_orders'] ?? 0) }} open order(s), {{ (int) ($ctx['open_disputes'] ?? 0) }} open dispute(s)</div>
                @if($siblings > 0)
                    <div>{{ $siblings }} other pending claim(s) on this site</div>
                @endif
            </dd>
            <dt class="col-sm-4">Proof</dt>
            <dd class="col-sm-8" style="white-space:pre-wrap;">{{ $item->proof_message }}</dd>
        @endif
        <dt class="col-sm-4">Status</dt>
        <dd class="col-sm-8">{{ $item->status }}</dd>
        <dt class="col-sm-4">Admin notes</dt>
        <dd class="col-sm-8" style="white-space:pre-wrap;">{{ $item->admin_notes ?: '—' }}</dd>
        <dt class="col-sm-4">Reviewer</dt>
        <dd class="col-sm-8">
            {{ $item->reviewer?->name ?? '—' }}
            @if($item->reviewed_at)
                <div class="text-muted">{{ $item->reviewed_at->diffForHumans() }}</div>
            @endif
        </dd>
        <dt class="col-sm-4">Submitted</dt>
        <dd class="col-sm-8">{{ optional($item->created_at)->toDayDateTimeString() ?: '—' }}</dd>
    </dl>
</template>
