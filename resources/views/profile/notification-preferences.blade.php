@extends('layouts.app')

@section('title', 'Email Preferences - SEOLinkBuildings')

@section('content')
<div class="container py-5" style="max-width:720px;">
    <div class="mb-4">
        <h2 class="fw-semibold mb-1" style="color:#185054;">Email Preferences</h2>
        <p class="text-muted mb-0">Choose which emails you want to receive. Security alerts always stay on.</p>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <form method="post" action="{{ route('profile.notifications.update') }}">
                @csrf
                <div class="list-group list-group-flush">
                    @foreach($preferences as $pref)
                        <label class="list-group-item px-0 d-flex justify-content-between align-items-center gap-3">
                            <span>
                                <strong>{{ $pref['label'] }}</strong>
                                @if($pref['locked'])
                                    <span class="badge bg-secondary ms-1">Required</span>
                                @endif
                            </span>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="preferences[{{ $pref['key'] }}]" value="1"
                                       @checked($pref['enabled'])
                                       @disabled($pref['locked'])>
                            </div>
                        </label>
                    @endforeach
                </div>
                <div class="d-flex justify-content-between align-items-center mt-4">
                    <a href="{{ route('profile') }}" class="btn btn-outline-secondary btn-sm">Back to profile</a>
                    <button type="submit" class="btn btn-primary btn-sm">Save preferences</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
