@extends('admin.layouts.app')

@section('title', 'Edit niche')

@section('content')
<div class="container-fluid py-3">
    @include('admin.partials.page-header', [
        'title' => $category->name,
        'subtitle' => $siteCount.' site(s) use this niche. Renaming updates their stored category.',
        'actionUrl' => route('admin.categories.index'),
        'actionLabel' => 'All niches',
        'actionIcon' => 'fa-arrow-left',
    ])

    <div class="card border-0 shadow-sm" style="max-width: 32rem;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.categories.update', $category) }}">
                @csrf
                @method('PUT')
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="categoryName">Niche name</label>
                    <input type="text" name="name" id="categoryName" class="form-control" maxlength="80" required
                           value="{{ old('name', $category->name) }}">
                    @error('name')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="categoryGroup">Group</label>
                    <input type="text" name="group" id="categoryGroup" class="form-control" maxlength="80" required
                           value="{{ old('group', $category->group) }}" list="categoryGroupList">
                    <datalist id="categoryGroupList">
                        @foreach($groups as $group)
                            <option value="{{ $group }}"></option>
                        @endforeach
                    </datalist>
                    @error('group')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
    </div>
</div>
@endsection
