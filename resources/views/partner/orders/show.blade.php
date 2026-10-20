@extends('partner.layouts.app')
@section('title', 'Order '.$order['order_number'])
@section('content')
    <p><a href="{{ \App\Support\PartnerDashboard::route('orders.index') }}">← Orders</a></p>
    <h1 class="partner-h1">Order {{ $order['order_number'] }}</h1>
    <p><span class="partner-badge">{{ $order['status_label'] }}</span></p>

    @if ($order['correction_reason'] !== '')
        <div class="partner-alert err">
            <strong>Awaiting correction</strong>
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
            <dt>Earnings</dt><dd>{{ $order['earnings_status'] ?: '—' }}@if($order['hold_until']) · held until {{ $order['hold_until'] }}@endif</dd>
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
            @endif
            @if (!empty($openCorrection))
                <form method="post" action="{{ \App\Support\PartnerDashboard::route('orders.decline', ['order' => $order['id'], 'correction' => $openCorrection->id]) }}" class="partner-form partner-follow-link">
                    @csrf
                    <label>Decline this correction (opens a dispute)
                        <textarea name="reason" required minlength="10" placeholder="Why this change is not possible">{{ old('reason') }}</textarea>
                    </label>
                    <button class="partner-btn danger" type="submit">Decline correction</button>
                </form>
            @endif
        </div>
    @endif

    <div class="partner-card" id="thread">
        <h2>Order thread</h2>
        <p class="partner-lead">Messages go through SLB. Contact details are removed. Buyer identity is never shown.</p>
        <ol class="partner-thread">
            @forelse ($messages as $message)
                <li class="partner-thread-item">
                    <div class="partner-thread-meta">
                        <strong>{{ $message['author_type'] }}</strong>
                        <time>{{ $message['created_at'] }}</time>
                        @if ($message['masked'])
                            <span class="partner-badge">masked</span>
                        @endif
                    </div>
                    <div class="partner-thread-body">{{ $message['body'] }}</div>
                    @foreach ($message['attachments'] as $file)
                        <div><a href="{{ \App\Support\PartnerDashboard::route('orders.attachment', ['order' => $order['id'], 'attachment' => $file['id']]) }}">{{ $file['filename'] }}</a></div>
                    @endforeach
                </li>
            @empty
                <li>No messages yet.</li>
            @endforelse
        </ol>
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('orders.reply', ['order' => $order['id']]) }}" enctype="multipart/form-data" class="partner-form">
            @csrf
            <label>Reply
                <textarea name="body" required maxlength="5000">{{ old('body') }}</textarea>
            </label>
            <label>Attachment (optional)
                <input type="file" name="attachment">
            </label>
            <button class="partner-btn" type="submit">Send</button>
        </form>
    </div>

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
