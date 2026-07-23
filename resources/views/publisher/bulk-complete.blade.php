@extends('publisher.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="mb-3">
        <a href="{{ route('publisher.websites') }}" class="small text-muted text-decoration-none">← Websites</a>
        <h3 class="mt-2 mb-1">Complete website details</h3>
        <p class="text-muted small mb-0">
            Metrics and geo were added by our team. Finish description, niches, link type, and timing for each site.
            Incomplete sites stay hidden from the catalog.
        </p>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @forelse($sites as $site)
        @php $open = (int) session('complete_site_id') === (int) $site->id || $errors->any() && (int) old('_site_id') === (int) $site->id; @endphp
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <div>
                        <h5 class="mb-0">{{ $site->site_name }}</h5>
                        <div class="small text-muted">
                            {{ $site->site_url }} · €{{ number_format((float) $site->price, 2) }}
                            · DR {{ $site->dr }} / DA {{ $site->da }}
                            · {{ strtoupper($site->language) }}/{{ strtoupper($site->country) }}
                        </div>
                    </div>
                    <span class="badge text-bg-light border align-self-start">Needs your details</span>
                </div>

                <form method="POST" action="{{ route('publisher.bulk-sites.complete.store', $site->id) }}" class="row g-3">
                    @csrf
                    <input type="hidden" name="_site_id" value="{{ $site->id }}">

                    <div class="col-md-6">
                        <label class="form-label">Example article URL *</label>
                        <input type="url" name="exampleUrl" class="form-control" required
                               value="{{ old('exampleUrl', $site->example_url) }}" placeholder="https://…/sample-post">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Turnaround *</label>
                        <select name="turnaround_time" class="form-select" required>
                            @foreach(['24h'=>'24 Hours','48h'=>'48 Hours','3days'=>'3 Days','5days'=>'5 Days','7days'=>'7 Days'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('turnaround_time', $site->turnaround_time) === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Publication *</label>
                        <select name="publicationTime" class="form-select" required>
                            @foreach(['6months'=>'6 Months','1year'=>'1 Year','permanent'=>'Permanent'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('publicationTime', $site->publication_time) === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Link type *</label>
                        <select name="link_type" class="form-select" required>
                            <option value="dofollow" @selected(old('link_type', $site->link_type) === 'dofollow')>DoFollow</option>
                            <option value="nofollow" @selected(old('link_type', $site->link_type) === 'nofollow')>NoFollow</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tag</label>
                        @php
                            $defaultTag = 'as_you_prefer';
                            if ($site->sponsored) {
                                $defaultTag = 'sponsored';
                            } elseif ($site->partner_material) {
                                $defaultTag = 'partner_material';
                            } elseif ($site->as_you_prefer) {
                                $defaultTag = 'as_you_prefer';
                            }
                        @endphp
                        <select name="site_tag" class="form-select">
                            <option value="as_you_prefer" @selected(old('site_tag', $defaultTag) === 'as_you_prefer')>As you prefer</option>
                            <option value="sponsored" @selected(old('site_tag', $defaultTag) === 'sponsored')>Sponsored</option>
                            <option value="partner_material" @selected(old('site_tag', $defaultTag) === 'partner_material')>Partner material</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Niches * (max 7)</label>
                        <select name="categories[]" class="form-select" multiple size="5" required>
                            @foreach($categories as $category)
                                <option value="{{ $category->name }}"
                                    @selected(collect(old('categories', $site->categories ?? []))->contains($category->name))>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description * (min 50 characters)</label>
                        <textarea name="siteDescription" class="form-control" rows="4" minlength="50" required>{{ old('siteDescription', str_starts_with((string) $site->description, 'Please replace') ? '' : $site->description) }}</textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Submit for review</button>
                    </div>
                </form>
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <h5>Nothing to complete</h5>
                <p class="text-muted mb-3">When our team seeds your bulk sites, they’ll appear here.</p>
                <a href="{{ route('publisher.websites') }}" class="btn btn-outline-primary">Back to websites</a>
            </div>
        </div>
    @endforelse
</div>
@endsection
