@extends('admin.layouts.app')

@section('title', 'Catalog niches')

@section('content')
<div class="container-fluid py-3">
    @include('admin.partials.page-header', [
        'title' => 'Catalog niches',
        'subtitle' => 'This list is the catalog picker. Renaming updates matching sites. Delete only unused niches.',
    ])

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.categories.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1" for="categoryName">Niche name</label>
                    <input type="text" name="name" id="categoryName" class="form-control" maxlength="80" required
                           value="{{ old('name') }}" placeholder="SaaS & B2B Software">
                    @error('name')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="categoryGroup">Group</label>
                    <input type="text" name="group" id="categoryGroup" class="form-control" maxlength="80" required
                           value="{{ old('group') }}" list="categoryGroupList" placeholder="Business & Finance">
                    <datalist id="categoryGroupList">
                        @foreach($groups as $group)
                            <option value="{{ $group }}"></option>
                        @endforeach
                    </datalist>
                    @error('group')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary">Add niche</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive admin-table-fit">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Niche</th>
                        <th>Group</th>
                        <th class="text-end">Sites</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($categories as $category)
                        <tr>
                            <td class="fw-semibold">{{ $category->name }}</td>
                            <td>{{ $category->group }}</td>
                            <td class="text-end">{{ (int) $category->site_count }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                @if((int) $category->site_count === 0)
                                    <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" class="d-inline"
                                          onsubmit="return confirm('Delete {{ addslashes($category->name) }} from the picker?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No niches yet. Seed categories or add one above.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
