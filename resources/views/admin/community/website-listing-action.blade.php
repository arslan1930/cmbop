@php
    $occupying = ($occupyingSites ?? [])[$item->id] ?? null;
@endphp
@if($occupying)
    <a href="{{ route('admin.sites.edit', $occupying->id) }}" class="btn btn-sm btn-outline-success">Already in catalog</a>
@else
    <a href="{{ route('admin.sites.create', \App\Support\CommunityInbox::createListingQuery($item)) }}" class="btn btn-sm btn-outline-success">Create listing</a>
@endif
