@extends('partner.layouts.app')
@section('title', 'Settings')
@section('content')
    <h1 class="partner-h1">Settings</h1>
    <p class="partner-lead">English only. Security and payout emails cannot be switched off.</p>

    <div class="partner-card">
        <h2>Name</h2>
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.name') }}" class="partner-form">
            @csrf
            <div class="stack">
                <label>Name
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" required>
                </label>
                <button class="partner-btn" type="submit">Save name</button>
            </div>
        </form>
    </div>

    <div class="partner-card">
        <h2>Password</h2>
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.password') }}" class="partner-form">
            @csrf
            <div class="stack">
                <label>Current password
                    <input type="password" name="current_password" required>
                </label>
                <label>New password (12+ characters)
                    <input type="password" name="password" required>
                </label>
                <label>Confirm new password
                    <input type="password" name="password_confirmation" required>
                </label>
                <button class="partner-btn" type="submit">Change password</button>
            </div>
        </form>
    </div>

    <div class="partner-card">
        <h2>Email</h2>
        <p>Current address: <strong>{{ $user->email }}</strong></p>
        @if ($pendingEmail)
            <p>Pending confirmation for <strong>{{ $pendingEmail }}</strong>. Check that inbox. The change applies only after you confirm.</p>
        @endif
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.email') }}" class="partner-form">
            @csrf
            <div class="stack">
                <label>New email
                    <input type="email" name="email" value="{{ old('email') }}" required>
                </label>
                <label>Current password
                    <input type="password" name="current_password" required>
                </label>
                <button class="partner-btn" type="submit">Request email change</button>
            </div>
        </form>
    </div>

    <div class="partner-card">
        <h2>Notifications</h2>
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.notifications') }}" class="partner-form">
            @csrf
            <div class="stack">
                <label><input type="checkbox" name="new_order" value="1" @checked(($prefs['new_order'] ?? true))> Email me about new orders</label>
                <label><input type="checkbox" name="correction" value="1" @checked(($prefs['correction'] ?? true))> Email me about corrections</label>
                <label><input type="checkbox" name="messages" value="1" @checked(($prefs['messages'] ?? true))> Email me about thread messages</label>
                <label><input type="checkbox" name="one_click_links" value="1" @checked($oneClick)> Allow one-click order links in email</label>
                <p class="partner-lead">Payout released and security emails always send.</p>
                <button class="partner-btn" type="submit">Save notifications</button>
            </div>
        </form>
    </div>

    <div class="partner-card">
        <h2>API keys</h2>
        <p class="partner-lead">At most two active keys. Keys are hashed; the secret is shown once.</p>
        @if (session('plain_api_key'))
            <div class="partner-alert">Copy this key now: <code>{{ session('plain_api_key') }}</code></div>
        @endif
        <table class="partner-table">
            <thead><tr><th>Name</th><th>Prefix</th><th>Mode</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($apiKeys as $key)
                <tr>
                    <td data-label="Name">{{ $key->name }}</td>
                    <td data-label="Prefix"><code>{{ $key->prefix }}</code></td>
                    <td data-label="Mode">{{ $key->mode }}</td>
                    <td data-label="Status">{{ $key->revoked_at ? 'revoked' : 'active' }}</td>
                    <td>
                        @if (! $key->revoked_at)
                            <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.keys.revoke', ['key' => $key->id]) }}">
                                @csrf
                                <button class="partner-btn secondary" type="submit">Revoke</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">No keys yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.keys') }}" class="partner-form">
            @csrf
            <div class="stack">
                <label>Key name
                    <input type="text" name="name" required maxlength="120" value="{{ old('name') }}">
                </label>
                <label>Mode
                    <select name="mode">
                        <option value="live">live</option>
                        <option value="test">test</option>
                    </select>
                </label>
                <button class="partner-btn" type="submit">Create API key</button>
            </div>
        </form>
    </div>

    <div class="partner-card">
        <h2>Webhooks</h2>
        <p class="partner-lead">Timestamp is in <code>SLB-Webhook-Timestamp</code>. Signature is <code>SLB-Signature: v1=</code> + HMAC_SHA256(secret, timestamp + '.' + body). Events include order.created, order.accepted, order.rejected, order.published, order.completed. Send a signed <code>webhook.test</code> event from here or <code>POST /api/partner/v1/webhooks/{id}/test</code>.</p>
        @if (session('webhook_secret'))
            <div class="partner-alert">Signing secret (once): <code>{{ session('webhook_secret') }}</code></div>
        @endif
        <table class="partner-table">
            <thead><tr><th>URL</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($webhooks as $hook)
                <tr>
                    <td data-label="URL">{{ $hook->url }}</td>
                    <td data-label="Status">{{ $hook->status }}</td>
                    <td>
                        @if ($hook->status === 'enabled')
                            <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.webhooks.test', ['endpoint' => $hook->id]) }}" class="partner-inline-form">
                                @csrf
                                <button class="partner-btn secondary" type="submit">Send test event</button>
                            </form>
                            <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.webhooks.disable', ['endpoint' => $hook->id]) }}" class="partner-inline-form">
                                @csrf
                                <button class="partner-btn secondary" type="submit">Disable</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">No webhook URL yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.webhooks') }}" class="partner-form">
            @csrf
            <div class="stack">
                <label>Endpoint URL
                    <input type="url" name="url" required value="{{ old('url') }}" placeholder="https://">
                </label>
                <button class="partner-btn" type="submit">Save webhook</button>
            </div>
        </form>
    </div>
@endsection
