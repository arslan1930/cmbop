@extends('layouts.app')

@section('title', 'Cannot connect assistant')

@section('content')
<link href="{{ asset('assets/css/auth-pages.css') }}?v={{ @filemtime(public_path('assets/css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

<div class="auth-page">
    <div class="container auth-shell">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="auth-card p-4 p-md-5">
                    <h1 class="h4 mb-3">Cannot connect</h1>
                    <p class="text-muted">{{ $message }}</p>
                    <p class="small text-muted mb-4">
                        MCP is for verified advertiser accounts. Publishers and unverified emails cannot connect Claude or Cursor.
                    </p>
                    <a href="{{ route('login') }}" class="btn btn-primary">Go to login</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
