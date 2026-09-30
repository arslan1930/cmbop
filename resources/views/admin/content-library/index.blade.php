@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Content Library</h1>
            <p class="text-muted mb-0">Advertiser articles. Policy and scan logs live under Moderation.</p>
        </div>
        <a href="{{ route('admin.moderation.index') }}" class="btn btn-outline-secondary btn-sm">
            Policy &amp; scans
        </a>
    </div>

    <div class="card border-0 shadow-sm mb-3 admin-deposits-filter-card">
    <div class="card-body">
    <form method="GET" action="{{ route('admin.content-library.index') }}" id="adminLibraryFilterForm" class="admin-deposits-filters admin-orders-filters"@if(!empty($liveSearchEnabled)) data-admin-filter-live="1"@endif>
        <div class="admin-orders-filters__grid">
        @if($userId)
            <input type="hidden" name="user_id" value="{{ $userId }}">
        @endif
        <input type="hidden" name="availability" value="{{ $availability }}" id="adminLibraryAvailability">
        <div class="admin-orders-filters__search">
            <x-slb-search-field name="q" id="adminContentLibrarySearch" :value="$search" placeholder="Title, file, email" input-class="form-control" label-class="form-label" />
        </div>
        <div>
            <label class="form-label" for="adminLibraryAdvertiser">Advertiser</label>
            <input type="search" name="advertiser" id="adminLibraryAdvertiser" class="form-control"
                   value="{{ $advertiserQuery ?? '' }}" placeholder="Name or email" autocomplete="off">
        </div>
        <div>
            <label class="form-label" for="adminLibraryCountry">Country</label>
            <select name="country" id="adminLibraryCountry" class="form-select">
                <option value="all" @selected($country === 'all')>All countries</option>
                @foreach($countries as $code)
                    <option value="{{ $code }}" @selected($country === $code)>{{ strtoupper($code) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="adminLibraryLanguage">Language</label>
            <select name="language" id="adminLibraryLanguage" class="form-select">
                <option value="all" @selected($language === 'all')>All languages</option>
                @foreach($languages as $code)
                    <option value="{{ $code }}" @selected($language === $code)>{{ strtoupper($code) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="adminLibrarySort">Sort</label>
            <select name="sort" id="adminLibrarySort" class="form-select">
                <option value="latest" @selected(($sort ?? 'latest') === 'latest')>Newest</option>
                <option value="title" @selected(($sort ?? '') === 'title')>Title</option>
                <option value="expires" @selected(($sort ?? '') === 'expires')>Expiry</option>
                <option value="uniqueness" @selected(($sort ?? '') === 'uniqueness')>Uniqueness</option>
                <option value="quality" @selected(($sort ?? '') === 'quality')>Quality</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="adminLibraryAttachment">On an order</label>
            <select name="attachment" id="adminLibraryAttachment" class="form-select">
                <option value="" @selected(($attachment ?? '') === '')>Any</option>
                <option value="order" @selected(($attachment ?? '') === 'order')>On an order</option>
                <option value="none" @selected(($attachment ?? '') === 'none')>Unattached</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="adminLibraryExpiring">Expiry</label>
            <select name="expiring" id="adminLibraryExpiring" class="form-select">
                <option value="" @selected(($expiring ?? '') === '')>Any</option>
                <option value="soon" @selected(($expiring ?? '') === 'soon')>Expires in 14 days</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="adminLibraryFrom">Uploaded from</label>
            <input type="date" name="from" id="adminLibraryFrom" class="form-control" value="{{ $from ?? '' }}">
        </div>
        <div>
            <label class="form-label" for="adminLibraryTo">Uploaded to</label>
            <input type="date" name="to" id="adminLibraryTo" class="form-control" value="{{ $to ?? '' }}">
        </div>
        <div class="admin-deposits-filters__actions admin-orders-filters__actions">
            <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="{{ route('admin.content-library.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </div>
        </div>
    </form>
    </div>
    </div>

    @if($filterUser)
        <div class="alert alert-light border py-2 px-3 small mb-3 d-flex flex-wrap align-items-center gap-2">
            <span>Advertiser filter:</span>
            <a href="{{ $filterUser->adminShowUrl() }}">
                {{ $filterUser->name ?: 'User #'.$filterUser->id }}
            </a>
            <span class="text-muted">{{ $filterUser->email }}</span>
            <a href="{{ route('admin.content-library.index', collect($filterQuery)->except(['user_id', 'advertiser'])->all()) }}" class="ms-auto">Clear advertiser</a>
        </div>
    @elseif(!empty($advertiserUnmatched))
        <div class="alert alert-light border py-2 px-3 small mb-3 d-flex flex-wrap align-items-center gap-2">
            <span>No advertiser matched “{{ $advertiserQuery }}”.</span>
            <a href="{{ route('admin.content-library.index', collect($filterQuery)->except(['user_id', 'advertiser'])->all()) }}" class="ms-auto">Clear advertiser</a>
        </div>
    @endif

    <div id="adminLibraryLiveRegion">
        @include('admin.content-library.results')
    </div>
</div>
@endsection

@push('scripts')
@if(!empty($liveSearchEnabled))
<script>
window.AdminLibraryBoot = {
    resultsUrl: @json(route('admin.content-library.results', absolute: false)),
    indexUrl: @json(route('admin.content-library.index', absolute: false)),
};
</script>
<script src="{{ asset('assets/js/admin-content-library.js') }}?v={{ @filemtime(public_path('assets/js/admin-content-library.js')) ?: '1' }}" defer></script>
@endif
@endpush
