@extends('advertiser.layouts.app')

@section('title', 'Saved searches')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Saved searches</h1>
            <p class="text-muted small mb-0">Named catalog filters. Optional daily email when new active sites match.</p>
        </div>
        <a href="{{ route('advertiser.catalog') }}" class="btn btn-sm btn-outline-primary">Back to catalog</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($searches->isEmpty())
                <p class="text-muted small mb-0 p-4">No saved searches yet. Apply filters on the catalog, then use Save search.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Filters</th>
                                <th>Email alert</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($searches as $search)
                                @php $params = $search->catalogQuery(); @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $search->name }}</td>
                                    <td class="small text-muted">
                                        @forelse($params as $key => $value)
                                            <span class="badge text-bg-light me-1">{{ $key }}={{ $value }}</span>
                                        @empty
                                            All listings
                                        @endforelse
                                    </td>
                                    <td>{{ $search->notify ? 'Daily' : 'Off' }}</td>
                                    <td class="text-end">
                                        <a href="{{ $search->catalogUrl() }}" class="btn btn-sm btn-outline-primary">Open</a>
                                        <form method="POST" action="{{ route('advertiser.saved-searches.destroy', $search) }}" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this saved search?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
