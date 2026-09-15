@extends('admin.layouts.app')

@section('title', 'Legal pages')

@section('content')
<div class="container-fluid py-3">
    @include('admin.partials.page-header', [
        'title' => 'Legal pages',
        'subtitle' => 'Built-in translations stay live until you publish a custom version for a locale. Revert any time.',
    ])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive admin-table-fit">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th>Published overrides</th>
                        <th>Drafts</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $row['label'] }}</div>
                                <div class="small text-muted">{{ $row['slug'] }}</div>
                            </td>
                            <td>
                                @forelse($row['published'] as $locale)
                                    <span class="badge text-bg-success">{{ strtoupper($locale) }}</span>
                                @empty
                                    <span class="text-muted small">Built-in translations</span>
                                @endforelse
                            </td>
                            <td>
                                @foreach($row['drafts'] as $locale)
                                    <span class="badge text-bg-secondary">{{ strtoupper($locale) }}</span>
                                @endforeach
                            </td>
                            <td class="text-end">
                                <a href="{{ $row['public_url'] }}" class="btn btn-sm btn-link" target="_blank" rel="noopener">View</a>
                                <a href="{{ route('admin.legal.edit', $row['slug']) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
