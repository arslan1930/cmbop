{{-- Legacy Balance page — merged into Add Funds --}}
@extends('advertiser.layouts.app')

@section('title', 'Add Funds')

@section('content')
<div class="container-fluid py-5 text-center">
    <p class="text-muted mb-3">The Balance page has moved.</p>
    <a href="{{ route('advertiser.add-funds') }}" class="btn btn-primary">Go to Add Funds</a>
</div>
@endsection
