@extends('partner.layouts.app')
@section('title', 'Order '.$order['order_number'])
@section('content')
    <p><a href="{{ \App\Support\PartnerDashboard::route('orders.index') }}">← Orders</a></p>
    <h1 class="partner-h1">Order {{ $order['order_number'] }}</h1>
    <p><span class="partner-badge">{{ $order['status_label'] }}</span></p>

    @if ($order['correction_reason'] !== '')
        <div class="partner-alert err">
            <strong>Correction requested</strong>
            <div>{{ $order['correction_reason'] }}</div>
        </div>
    @endif
    @if ($order['content_revision_requested'])
        <div class="partner-alert err">Wait for a revised article before submitting a live URL.</div>
    @endif

    <div class="partner-card">
        <dl class="partner-dl">
            <dt>Site</dt><dd>{{ $order['site_domain'] }}</dd>
            <dt>Buyer</dt><dd>{{ $order['buyer_ref'] }}</dd>
            <dt>Your payout</dt><dd>{{ $order['your_payout_formatted'] }}</dd>
            <dt>Anchor</dt><dd>{{ $order['anchor_text'] ?: '—' }}</dd>
            <dt>Target URL</dt><dd>@if($order['target_url'])<a href="{{ $order['target_url'] }}" rel="noopener noreferrer">{{ $order['target_url'] }}</a>@else — @endif</dd>
            <dt>Brief / article</dt>
            <dd>
                @if ($order['content_download_url'])
                    <a href="{{ $order['content_download_url'] }}">Download article</a>
                @elseif ($order['content_link'])
                    <a href="{{ $order['content_link'] }}" rel="noopener noreferrer">Open brief</a>
                @else
                    —
                @endif
            </dd>
            <dt>Live URL</dt><dd>{{ $order['live_url'] ?: 'Not submitted' }}</dd>
        </dl>
    </div>

    @if ($order['can_accept'] || $order['can_reject'] || $order['can_submit'] || $order['can_resubmit'])
        <div class="partner-card">
            <h2>Actions</h2>
            @if ($order['can_accept'])
                <form method="post" action="{{ \App\Support\PartnerDashboard::route('orders.accept', ['order' => $order['id']]) }}" class="partner-inline-form">
                    @csrf
                    <button class="partner-btn" type="submit">Accept</button>
                </form>
            @endif
            @if ($order['can_reject'])
                <form method="post" action="{{ \App\Support\PartnerDashboard::route('orders.reject', ['order' => $order['id']]) }}" class="partner-form partner-follow-link">
                    @csrf
                    <label>Reject reason (min. 10 characters)
                        <textarea name="reason" required minlength="10">{{ old('reason') }}</textarea>
                    </label>
                    <button class="partner-btn danger" type="submit">Reject</button>
                </form>
            @endif
            @if ($order['can_submit'] || $order['can_resubmit'])
                <form method="post" action="{{ \App\Support\PartnerDashboard::route('orders.submit', ['order' => $order['id']]) }}" class="partner-form partner-follow-link">
                    @csrf
                    <label>Live URL
                        <input type="url" name="live_url" value="{{ old('live_url', $order['live_url']) }}" required placeholder="https://">
                    </label>
                    <button class="partner-btn" type="submit">{{ $order['can_resubmit'] ? 'Resubmit live URL' : 'Submit live URL' }}</button>
                </form>
                @if ($order['can_resubmit'] && $order['live_url'])
                    <form method="post" action="{{ \App\Support\PartnerDashboard::route('orders.fixed', ['order' => $order['id']]) }}" class="partner-follow-link">
                        @csrf
                        <button class="partner-btn secondary" type="submit">I fixed it on the same URL</button>
                    </form>
                @endif
            @endif
        </div>
    @endif

    <div class="partner-card">
        <h2>Timeline</h2>
        <ol class="partner-timeline">
            @forelse ($order['timeline'] as $event)
                <li>
                    <time>{{ $event['at'] }}</time>
                    <strong>{{ $event['title'] }}</strong>
                    @if ($event['description'] !== '')
                        <div>{{ $event['description'] }}</div>
                    @endif
                </li>
            @empty
                <li>No timeline events yet.</li>
            @endforelse
        </ol>
    </div>
@endsection
