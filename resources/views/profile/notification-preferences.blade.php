@php
    $user = auth()->user();
@endphp
@extends(\App\Support\PortalShell::layout($user))

@section('title', 'Notification Preferences')

@section('content')
<div class="{{ \App\Support\PortalShell::contentClass($user) }}" style="max-width:720px;">
    <div class="mb-4">
        <h1 class="fw-semibold mb-1" style="color:#1a585e;">Notification Preferences</h1>
        <p class="text-muted mb-0">Choose which emails and in-app (bell) notifications you want. Security alerts always stay on.</p>
    </div>

    <form method="post" action="{{ route('profile.notifications.update') }}">
        @csrf

        <div class="card border-0 shadow-sm mb-3" style="border-radius:14px;">
            <div class="card-body p-4">
                <h2 class="h6 fw-semibold mb-3" style="color:#1a585e;">In-app notifications</h2>
                @if(empty($inAppPrefsAvailable))
                    <p class="text-muted small mb-0">In-app category toggles will appear after the preferences table is installed. Until then, all bell notifications stay on (current behaviour).</p>
                @else
                    <p class="text-muted small mb-3">Control what appears under the bell icon. Defaults match current behaviour (all on).</p>
                    <div class="list-group list-group-flush">
                        @foreach($inAppPreferences as $pref)
                            <label class="list-group-item px-0 d-flex justify-content-between align-items-center gap-3">
                                <span><strong>{{ $pref['label'] }}</strong></span>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           name="in_app[{{ $pref['key'] }}]" value="1"
                                           @checked($pref['enabled'])>
                                </div>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="card border-0 shadow-sm" style="border-radius:14px;">
            <div class="card-body p-4">
                <h2 class="h6 fw-semibold mb-3" style="color:#1a585e;">Email notifications</h2>
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
            </div>
        </div>
    </form>
</div>
@endsection
