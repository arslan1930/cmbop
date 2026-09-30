@php
    $occupying = ($occupyingSites ?? [])[$item->id] ?? null;
@endphp
@if($occupying)
    <a href="{{ \App\Support\CatalogProblemReport::staffListingUrl($occupying) ?: staff_route('sites.index', array_filter(['publisher' => $occupying->publisher_id, 'site' => $occupying->id])) }}" class="btn btn-sm btn-outline-success">Already in catalog</a>
@else
    <a href="{{ route('admin.sites.create', \App\Support\CommunityInbox::createListingQuery($item)) }}" class="btn btn-sm btn-outline-success">Create listing</a>
@endif
