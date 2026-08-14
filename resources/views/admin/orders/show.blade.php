@extends('admin.layouts.app')

@section('content')
@php
    $item = $order->items->first();
    $site = $item?->site;
    $publisher = $site?->publisher;
    $statusClass = match ($order->status) {
        'completed' => 'success',
        'cancelled' => 'danger',
        'review' => 'info',
        'processing' => 'primary',
        'scheduled' => 'warning',
        default => 'secondary',
    };
    $payClass = match ($order->payment_status) {
        'paid' => 'success',
        'failed' => 'danger',
        'refunded' => 'secondary',
        default => 'warning',
    };
    $paymentsUrl = route('admin.payments', array_filter([
        'search' => $order->order_number,
        'payment_status' => $order->isUnpaidOps() ? 'unpaid' : null,
    ]));
    $remindableItems = $order->items
        ->filter(fn ($line) => $line->canAdminRemindPublisher($order))
        ->values();
    $hasPublicationSchedule = $order->hasPublicationSchedule();
    $scheduledLocal = $hasPublicationSchedule
        ? $order->scheduledPublishAtInScheduleTimezone()
        : null;
    $scheduleTimezone = $hasPublicationSchedule
        ? $order->scheduleTimezoneOrUtc()
        : null;
    $advertiserAdminUrl = $order->user
        ? route('admin.users.index', ['user' => $order->user->id]).'#user-'.$order->user->id
        : null;
    $publisherAdminUrl = $publisher
        ? route('admin.users.index', ['user' => $publisher->id]).'#user-'.$publisher->id
        : null;
@endphp
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Order #' . $order->order_number,
        'subtitle' => 'Stage can be corrected here · chat is read-only and payments use Order Payments',
        'actionUrl' => route('admin.orders.index'),
        'actionLabel' => 'All orders',
        'actionIcon' => 'fa-arrow-left',
    ])

    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge text-bg-{{ $statusClass }}">Status: {{ $order->status }}</span>
        <span class="badge text-bg-{{ $payClass }}">Payment: {{ $order->payment_status }}</span>
        <span class="badge text-bg-light text-dark border">{{ strtoupper((string) $order->payment_method) ?: '—' }}</span>
        <span class="badge text-bg-light text-dark border">€{{ number_format((float) $order->total_amount, 2) }}</span>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-overview-btn" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab">Overview</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-chat-btn" data-bs-toggle="tab" data-bs-target="#tab-chat" type="button" role="tab">
                Chat
                @if($messages->count())
                    <span class="badge bg-secondary">{{ $messages->count() }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-activity-btn" data-bs-toggle="tab" data-bs-target="#tab-activity" type="button" role="tab">
                Activity
                @if(count($activities))
                    <span class="badge bg-secondary">{{ count($activities) }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-payment-btn" data-bs-toggle="tab" data-bs-target="#tab-payment" type="button" role="tab">Payment</button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0"><strong>Parties</strong></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="small text-muted">Advertiser</div>
                                <div class="fw-semibold">
                                    @if($advertiserAdminUrl)
                                        <a href="{{ $advertiserAdminUrl }}" class="link-dark">{{ $order->user->name }}</a>
                                    @else
                                        {{ $order->user->name ?? '—' }}
                                    @endif
                                </div>
                                <div class="small text-muted">{{ $order->user->email ?? '' }}</div>
                            </div>
                            <div>
                                <div class="small text-muted">Publisher</div>
                                <div class="fw-semibold">
                                    @if($publisherAdminUrl)
                                        <a href="{{ $publisherAdminUrl }}" class="link-dark">{{ $publisher->name }}</a>
                                    @else
                                        {{ $publisher->name ?? '—' }}
                                    @endif
                                </div>
                                <div class="small text-muted">{{ $publisher->email ?? '' }}</div>
                                @if($remindableItems->isNotEmpty())
                                    <div class="mt-3 d-flex flex-wrap gap-2 align-items-center" id="remind-publisher">
                                        @foreach($remindableItems as $remindItem)
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary js-remind-publisher"
                                                    data-remind-url="{{ route('admin.orders.remind-publisher', $remindItem) }}">
                                                <i class="fa fa-bell me-1"></i>
                                                {{ $remindItem->adminRemindTrack() === 'accept' ? 'Remind to accept' : 'Remind to publish' }}
                                                @if($remindableItems->count() > 1)
                                                    · {{ $remindItem->site_name ?: ($remindItem->site?->site_name ?: 'placement') }}
                                                @endif
                                            </button>
                                        @endforeach
                                    </div>
                                    <div class="small text-muted mt-1">Sends now. Does not use up the automated reminder ladder.</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0"><strong>Placements</strong></div>
                        <div class="card-body">
                            @forelse($order->items as $line)
                                @php
                                    $lineSite = $line->site;
                                    $linePublisher = $lineSite?->publisher;
                                    $briefAnchor = $line->briefAnchorText();
                                    $briefTarget = $line->briefTargetUrl();
                                    $publicContentLink = $line->publicContentLink();
                                @endphp
                                @if(! $loop->first)
                                    <hr class="my-3">
                                @endif
                                <div class="mb-2"><span class="text-muted small">Site</span>
                                    <div class="fw-semibold">
                                        @if($lineSite)
                                            <a href="{{ route('admin.sites.edit', $lineSite->id) }}" class="link-dark">{{ $line->site_name ?? ($lineSite->site_name ?? '—') }}</a>
                                        @else
                                            {{ $line->site_name ?? '—' }}
                                        @endif
                                    </div>
                                </div>
                                @if($line->site_url || $lineSite?->site_url)
                                    <div class="mb-2"><a href="{{ $line->site_url ?? $lineSite->site_url }}" target="_blank" rel="noopener">{{ $line->site_url ?? $lineSite->site_url }}</a></div>
                                @endif
                                @if($line->hasHomepagePlacement())
                                    <div class="mb-2">
                                        <span class="text-muted small">Homepage placement</span>
                                        <div>
                                            {{ (int) $line->homepage_days }} day{{ (int) $line->homepage_days === 1 ? '' : 's' }}
                                            @if((float) ($line->homepage_price ?? 0) > 0)
                                                · +€{{ number_format((float) $line->homepage_price, 2) }}
                                            @else
                                                · Free
                                            @endif
                                        </div>
                                    </div>
                                @endif
                                @if($line->offersSocialPromotion())
                                    <div class="mb-2">
                                        <span class="text-muted small">Social promotion</span>
                                        <div>
                                            {{ collect($line->enabledSocialChannels())->map(fn ($c) => $line->socialChannelLabel($c))->implode(', ') }}
                                            · included
                                        </div>
                                        @if($line->hasSocialPostUrls())
                                            <ul class="mb-0 ps-3 mt-1">
                                                @foreach($line->socialPostUrls() as $channel => $url)
                                                    <li class="small">
                                                        <strong>{{ $line->socialChannelLabel($channel) }}:</strong>
                                                        <a class="live-url" href="{{ $url }}" target="_blank" rel="noopener">{{ $url }}</a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <div class="small text-muted">Post URLs not submitted yet</div>
                                        @endif
                                    </div>
                                @endif
                                <div class="mb-2"><span class="text-muted small">Anchor text</span>
                                    <div>{{ $briefAnchor ?: '—' }}</div>
                                </div>
                                <div class="mb-2"><span class="text-muted small">Target URL</span>
                                    <div>
                                        @if($briefTarget)
                                            <a href="{{ $briefTarget }}" target="_blank" rel="noopener">{{ $briefTarget }}</a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="mb-2"><span class="text-muted small">Accepted at</span>
                                    <div>{{ optional($line->accepted_at)->format('M j, Y g:i A') ?: 'Not accepted' }}</div>
                                </div>
                                <div class="mb-2"><span class="text-muted small">Live URL</span>
                                    <div>
                                        @if($line->live_url)
                                            <a class="live-url" href="{{ $line->live_url }}" target="_blank" rel="noopener">{{ $line->live_url }}</a>
                                        @else
                                            <span class="text-muted">Not submitted</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="mb-2"><span class="text-muted small">Modification requested</span>
                                    <div>{{ $line->modification_requested ?: 'no' }}</div>
                                </div>
                                <div class="mb-2"><span class="text-muted small">Content</span>
                                    <div class="d-flex flex-wrap gap-2 align-items-center">
                                        @if($publicContentLink)
                                            <a href="{{ $publicContentLink }}" target="_blank" rel="noopener">Open content link</a>
                                        @endif
                                        @if($line->hasDownloadableContent())
                                            <a href="{{ route('admin.orders.content.download', $line) }}" class="btn btn-sm btn-outline-secondary">
                                                <i class="fa fa-download me-1"></i>
                                                {{ $line->contentSubmission?->original_filename ?: ($line->content_original_name ?: 'Download file') }}
                                            </a>
                                        @endif
                                        @if(! $publicContentLink && ! $line->hasDownloadableContent())
                                            <span class="text-muted">No file uploaded</span>
                                        @endif
                                    </div>
                                </div>
                                @if($linePublisher && $loop->count > 1)
                                    <div class="small text-muted">
                                        Publisher:
                                        <a href="{{ route('admin.users.index', ['user' => $linePublisher->id]) }}#user-{{ $linePublisher->id }}" class="link-dark">{{ $linePublisher->name }}</a>
                                    </div>
                                @endif
                            @empty
                                <div class="text-muted">No placements on this order.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-0"><strong>Order details</strong></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3"><span class="text-muted small">Reference</span><div>{{ $order->reference_code ?: '—' }}</div></div>
                                <div class="col-md-3"><span class="text-muted small">Created</span><div>{{ optional($order->created_at)->format('M j, Y g:i A') }}</div></div>
                                <div class="col-md-3"><span class="text-muted small">Paid at</span><div>{{ optional($order->paid_at)->format('M j, Y g:i A') ?: '—' }}</div></div>
                                <div class="col-md-3"><span class="text-muted small">Completed at</span><div>{{ optional($order->completed_at)->format('M j, Y g:i A') ?: '—' }}</div></div>
                                <div class="col-md-3"><span class="text-muted small">Subtotal / Tax / Total</span>
                                    <div>€{{ number_format((float) $order->subtotal, 2) }} · €{{ number_format((float) $order->tax, 2) }} · €{{ number_format((float) $order->total_amount, 2) }}</div>
                                </div>
                                @if($hasPublicationSchedule)
                                    <div class="col-12" id="order-schedule">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <span class="text-muted small">Publication mode</span>
                                                <div>{{ $order->isScheduled() ? 'Scheduled' : 'Immediate' }}</div>
                                            </div>
                                            <div class="col-md-3">
                                                <span class="text-muted small">Scheduled for</span>
                                                <div>
                                                    @if($scheduledLocal)
                                                        {{ $scheduledLocal->format('M j, Y g:i A') }}
                                                        <span class="text-muted">{{ $scheduleTimezone }}</span>
                                                    @else
                                                        —
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <span class="text-muted small">Released at</span>
                                                <div>{{ optional($order->schedule_released_at)->format('M j, Y g:i A') ?: 'Not released' }}</div>
                                            </div>
                                            <div class="col-md-3">
                                                <span class="text-muted small">Reminder sent at</span>
                                                <div>{{ optional($order->schedule_reminder_sent_at)->format('M j, Y g:i A') ?: 'Not sent' }}</div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @if($order->status === 'completed' || ($disputes ?? collect())->isNotEmpty())
                <div class="col-12" id="order-disputes">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <strong>Link-removed dispute / clawback</strong>
                            @if(!empty($canOpenDispute))
                                <button type="button" class="btn btn-sm btn-outline-danger" id="adminOpenDisputeBtn">
                                    <i class="fa fa-flag me-1"></i> Open dispute
                                </button>
                            @endif
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-3">
                                If the publisher removed the live article after completion, uphold to claw back the publisher payout
                                (debt + withdrawal freeze on shortfall) and refund the advertiser item price.
                            </p>
                            @forelse(($disputes ?? collect()) as $dispute)
                                @php
                                    $dBadge = match ($dispute->status) {
                                        'upheld' => 'danger',
                                        'dismissed' => 'secondary',
                                        default => 'warning',
                                    };
                                @endphp
                                <div class="border rounded-3 p-3 mb-3">
                                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                        <span class="badge text-bg-{{ $dBadge }}">{{ ucfirst($dispute->status) }}</span>
                                        <span class="small text-muted">#{{ $dispute->id }} · {{ optional($dispute->created_at)->format('M j, Y g:i A') }}</span>
                                    </div>
                                    <div class="mb-2"><span class="text-muted small">Reason</span><div>{{ $dispute->reason }}</div></div>
                                    @if($dispute->admin_notes)
                                        <div class="mb-2"><span class="text-muted small">Admin notes</span><div>{{ $dispute->admin_notes }}</div></div>
                                    @endif
                                    @if($dispute->isUpheld())
                                        <div class="row g-2 small">
                                            <div class="col-md-4">Publisher debited: <strong>€{{ number_format((float) $dispute->publisher_debited, 2) }}</strong></div>
                                            <div class="col-md-4">Advertiser credited: <strong>€{{ number_format((float) $dispute->advertiser_credited, 2) }}</strong></div>
                                            <div class="col-md-4">Debt created: <strong>€{{ number_format((float) $dispute->debt_created, 2) }}</strong></div>
                                        </div>
                                    @endif
                                    @if($dispute->isOpen())
                                        <div class="d-flex flex-wrap gap-2 mt-3">
                                            <button type="button"
                                                    class="btn btn-sm btn-danger js-resolve-dispute"
                                                    data-action="uphold"
                                                    data-resolve-url="{{ route('admin.orders.disputes.uphold', $dispute) }}">
                                                Uphold &amp; claw back
                                            </button>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary js-resolve-dispute"
                                                    data-action="dismiss"
                                                    data-resolve-url="{{ route('admin.orders.disputes.dismiss', $dispute) }}">
                                                Dismiss
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-muted mb-0">No disputes filed for this order yet.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
                @endif

                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-0"><strong>Order stage</strong></div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Use this to unstick an order sitting on the wrong stage — a publisher who
                                accepted by mistake, or one waiting on a review that never opened.
                                Completing or cancelling moves money, so those stay with advertiser
                                approval and the refund tools.
                            </p>

                            @if($canOverrideStatus && count($statusTargets))
                                <form method="POST" action="{{ route('admin.orders.status', $order->id) }}" id="adminOrderStageForm">
                                    @csrf
                                    <div class="row g-2 align-items-start">
                                        <div class="col-md-3">
                                            <label class="form-label small mb-1" for="adminOrderStage">Move to</label>
                                            <select class="form-select form-select-sm" id="adminOrderStage" name="status" required>
                                                @foreach($statusTargets as $target)
                                                    <option value="{{ $target }}">{{ ucfirst($target) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-7">
                                            <label class="form-label small mb-1" for="adminOrderStageReason">Reason (shown to both sides)</label>
                                            <input type="text" class="form-control form-control-sm" id="adminOrderStageReason"
                                                   name="reason" minlength="5" maxlength="500" required
                                                   placeholder="e.g. Publisher accepted by mistake — moving back to pending">
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end" style="min-height: 58px;">
                                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                                <i class="fa fa-shuffle me-1"></i> Move
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            @elseif(in_array($order->status, ['completed', 'cancelled'], true))
                                <p class="mb-0">This order is <strong>{{ $order->status }}</strong> and cannot be reopened from here.</p>
                            @elseif($order->payment_status !== 'paid')
                                <p class="mb-0">Payment is <strong>{{ $order->payment_status }}</strong>. Settle it in Order Payments before moving the stage.</p>
                            @else
                                <p class="mb-0">No other stage is available for this order.</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-chat" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong>Order chat</strong>
                    <span class="badge text-bg-light text-dark border">Read-only</span>
                </div>
                <div class="card-body" style="max-height: 520px; overflow-y: auto;">
                    @forelse($messages as $msg)
                        @php
                            $isAdvertiser = $msg->sender_type === 'advertiser';
                        @endphp
                        <div class="d-flex {{ $isAdvertiser ? 'justify-content-start' : 'justify-content-end' }} mb-3">
                            <div class="border rounded-3 px-3 py-2 {{ $msg->is_blocked ? 'bg-secondary-subtle text-secondary' : ($isAdvertiser ? 'bg-light' : 'bg-primary-subtle') }}" style="max-width: 75%;{{ $msg->is_blocked ? ' opacity:.9;' : '' }}">
                                <div class="small text-muted mb-1">
                                    {{ $msg->user->name ?? ucfirst($msg->sender_type) }}
                                    · {{ ucfirst($msg->sender_type) }}
                                    · {{ optional($msg->created_at)->format('M j, Y g:i A') }}
                                    @if($msg->is_blocked)
                                        · <span class="badge text-bg-secondary">Not delivered</span>
                                    @endif
                                </div>
                                <div>{{ $msg->message }}</div>
                                @if($msg->is_blocked)
                                    <div class="small text-secondary mt-1">Blocked — personal contact details aren’t allowed.</div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-5">
                            <i class="fa fa-comments fa-2x mb-2"></i>
                            <p class="mb-0">No messages on this order yet.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-activity" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0"><strong>Activity timeline</strong></div>
                <div class="card-body">
                    @forelse($activities as $activity)
                        <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                            <div class="pt-1">
                                <span class="badge text-bg-{{ $activity['badge_color'] ?? 'secondary' }}">
                                    <i class="fa fa-{{ $activity['icon'] ?? 'circle' }}"></i>
                                </span>
                            </div>
                            <div>
                                <div class="fw-semibold">{{ $activity['title'] ?? 'Event' }}</div>
                                @if(!empty($activity['description']))
                                    <div class="small">{{ $activity['description'] }}</div>
                                @endif
                                <div class="small text-muted">
                                    {{ $activity['actor_name'] ?? 'System' }}
                                    @if(!empty($activity['actor_role'])) · {{ $activity['actor_role'] }} @endif
                                    · {{ $activity['exact_time'] ?? ($activity['relative_time'] ?? '') }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-4">No activity recorded yet.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-payment" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0"><strong>Payment summary</strong></div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><span class="text-muted small">Payment status</span><div class="fw-semibold">{{ $order->payment_status }}</div></div>
                        <div class="col-md-3"><span class="text-muted small">Method</span><div class="fw-semibold">{{ $order->payment_method ?: '—' }}</div></div>
                        <div class="col-md-3"><span class="text-muted small">Total</span><div class="fw-semibold">€{{ number_format((float) $order->total_amount, 2) }}</div></div>
                        <div class="col-md-3"><span class="text-muted small">Paid at</span><div>{{ optional($order->paid_at)->format('M j, Y g:i A') ?: '—' }}</div></div>
                    </div>
                    <p class="text-muted small mb-3">
                        To mark paid, failed, or refunded, use the Order Payments tools. This screen is inspection-only.
                    </p>
                    <a href="{{ $paymentsUrl }}" class="btn btn-primary btn-sm">
                        <i class="fa fa-money-bill me-1"></i> Open Order Payments
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        || '{{ csrf_token() }}';

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(body || {}),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.message || 'Request failed');
        }
        return data;
    }

    // Both sides are notified, so make the move deliberate rather than one click.
    document.getElementById('adminOrderStageForm')?.addEventListener('submit', function (e) {
        if (this.dataset.confirmed === '1') return;

        e.preventDefault();
        const form = this;
        const target = form.querySelector('#adminOrderStage').value;

        Swal.fire({
            title: 'Move this order to ' + target + '?',
            text: 'The advertiser and publisher are both notified, and the reason you gave is shown to them.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Move to ' + target,
            cancelButtonText: 'Cancel',
        }).then((result) => {
            if (!result.isConfirmed) return;
            form.dataset.confirmed = '1';
            form.submit();
        });
    });

    document.getElementById('adminOpenDisputeBtn')?.addEventListener('click', async () => {
        const { value: reason } = await Swal.fire({
            title: 'Open link-removed dispute',
            input: 'textarea',
            inputLabel: 'Reason (10–1000 characters)',
            inputPlaceholder: 'Describe why this completed placement is being disputed…',
            inputAttributes: { maxlength: 1000 },
            showCancelButton: true,
            confirmButtonText: 'Open dispute',
            customClass: { confirmButton: 'slb-swal-danger' },
            inputValidator: (v) => {
                const t = (v || '').trim();
                if (t.length < 10) return 'Reason must be at least 10 characters.';
                if (t.length > 1000) return 'Reason must be at most 1000 characters.';
                return null;
            },
        });
        if (!reason) return;
        try {
            const data = await postJson(@json(route('admin.orders.disputes.open', $order->id)), { reason });
            await Swal.fire('Opened', data.message, 'success');
            window.location.reload();
        } catch (e) {
            Swal.fire('Error', e.message || 'Failed', 'error');
        }
    });

    document.querySelectorAll('.js-remind-publisher').forEach((btn) => {
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            try {
                const data = await postJson(btn.dataset.remindUrl, {});
                btn.outerHTML = '<span class="text-success small fw-semibold">'
                    + '<i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Reminder sent</span>';
                if (window.showAppToast) {
                    window.showAppToast(data.message || 'Reminder sent', 'success');
                }
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Retry';
                if (window.showAppToast) {
                    window.showAppToast(e.message || 'Could not send the reminder', 'error');
                } else {
                    Swal.fire('Error', e.message || 'Could not send the reminder', 'error');
                }
            }
        });
    });

    async function resolveDispute(url, action) {
        const isUphold = action === 'uphold';
        const { value: notes } = await Swal.fire({
            title: isUphold ? 'Uphold dispute & claw back' : 'Dismiss dispute',
            html: isUphold
                ? '<p class="small text-start mb-2">This debits the publisher payout (or creates debt), refunds the advertiser, and sets payment status to refunded.</p>'
                : '',
            input: 'textarea',
            inputLabel: 'Resolution notes (10–1000 characters)',
            inputAttributes: { maxlength: 1000 },
            showCancelButton: true,
            confirmButtonText: isUphold ? 'Uphold' : 'Dismiss',
            customClass: { confirmButton: isUphold ? 'slb-swal-danger' : 'slb-swal-muted' },
            inputValidator: (v) => {
                const t = (v || '').trim();
                if (t.length < 10) return 'Notes must be at least 10 characters.';
                if (t.length > 1000) return 'Notes must be at most 1000 characters.';
                return null;
            },
        });
        if (!notes) return;
        try {
            const data = await postJson(url, { admin_notes: notes });
            await Swal.fire('Done', data.message, 'success');
            window.location.reload();
        } catch (e) {
            Swal.fire('Error', e.message || 'Failed', 'error');
        }
    }

    document.querySelectorAll('.js-resolve-dispute').forEach((btn) => {
        btn.addEventListener('click', () => resolveDispute(btn.dataset.resolveUrl, btn.dataset.action));
    });
})();
</script>
@endsection
