@extends(staff_layout())

@section('content')
@php
    $selectedStatus = $status ?? \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER;
    $q = $q ?? '';
    $mine = ! empty($mine);
    $sort = $sort ?? '';
    $handlerFilter = $handlerFilter ?? '';
    $slaAmberDays = (int) ($slaAmberDays ?? 3);
    $slaRedDays = (int) ($slaRedDays ?? 7);
    $lastReminded = $lastReminded ?? [];
    $staffAssignees = $staffAssignees ?? collect();
    $handlerCounts = $handlerCounts ?? [];
    $listQuery = array_filter([
        'q' => $q !== '' ? $q : null,
        'status' => $selectedStatus !== \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER ? $selectedStatus : null,
        'mine' => $mine ? 1 : null,
        'handler' => (! $mine && $handlerFilter !== '') ? $handlerFilter : null,
        'sort' => in_array($sort, ['newest', 'pending', 'pending_asc', 'est', 'est_desc', 'sites', 'sites_desc'], true)
            || ($sort === 'oldest' && $selectedStatus !== \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER)
            ? $sort
            : null,
    ], static fn ($value) => $value !== null && $value !== '');
    $sortUrl = function (string $next) use ($listQuery) {
        $query = $listQuery;
        $query['sort'] = $next;

        return staff_route('bulk-site-requests.index', $query);
    };
    $waitingSortNext = $sort === 'oldest' ? 'newest' : 'oldest';
    $pendingSortNext = $sort === 'pending' ? 'pending_asc' : 'pending';
    $estSortNext = $sort === 'est_desc' ? 'est' : 'est_desc';
    $sitesSortNext = $sort === 'sites_desc' ? 'sites' : 'sites_desc';
    $strip = [
        [
            'key' => 'you',
            'label' => 'Waiting on you',
            'count' => (int) ($waitingOnYouCount ?? 0),
            'url' => staff_route('bulk-site-requests.index', array_filter(['q' => $q !== '' ? $q : null])),
            'hint' => 'URL + price rows you can fill and Done, or sites withdrawn from review that still need Publish ready.',
            'active' => $selectedStatus === \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER,
        ],
        [
            'key' => 'publisher',
            'label' => 'Waiting on publisher',
            'count' => (int) ($waitingOnPublisherCount ?? 0),
            'url' => staff_route('bulk-site-requests.index', array_filter(['status' => \App\Support\MarketingOpsQueues::FILTER_WAITING_PUBLISHER, 'q' => $q !== '' ? $q : null])),
            'hint' => 'Drafts still with the publisher, including staff-added invites waiting on Accept.',
            'active' => $selectedStatus === \App\Support\MarketingOpsQueues::FILTER_WAITING_PUBLISHER,
        ],
        [
            'key' => 'overdue',
            'label' => 'Overdue',
            'count' => (int) ($overdueCount ?? 0),
            'url' => staff_route('bulk-site-requests.index', array_filter(['status' => \App\Support\MarketingOpsQueues::FILTER_OVERDUE, 'q' => $q !== '' ? $q : null])),
            'hint' => 'Open batches older than '.$slaRedDays.' days. Age pills turn red at this target.',
            'active' => $selectedStatus === \App\Support\MarketingOpsQueues::FILTER_OVERDUE,
        ],
        [
            'key' => 'open',
            'label' => 'All open',
            'count' => (int) ($allOpenCount ?? 0),
            'url' => staff_route('bulk-site-requests.index', array_filter(['status' => 'all', 'q' => $q !== '' ? $q : null])),
            'hint' => 'Every open batch, including waiting on the publisher.',
            'active' => $selectedStatus === 'all',
        ],
        [
            'key' => 'finished',
            'label' => 'Finished',
            'count' => (int) ($finishedCount ?? 0),
            'url' => staff_route('bulk-site-requests.index', array_filter(['status' => \App\Models\BulkSiteRequest::STATUS_COMPLETED, 'q' => $q !== '' ? $q : null])),
            'hint' => 'No leftover rows. Verify leftover live sites on Sites if needed.',
            'active' => $selectedStatus === \App\Models\BulkSiteRequest::STATUS_COMPLETED,
        ],
        [
            'key' => 'cancelled',
            'label' => 'Cancelled',
            'count' => (int) ($cancelledCount ?? 0),
            'url' => staff_route('bulk-site-requests.index', array_filter(['status' => \App\Models\BulkSiteRequest::STATUS_CANCELLED, 'q' => $q !== '' ? $q : null])),
            'hint' => 'Cancelled batches. History is kept.',
            'active' => $selectedStatus === \App\Models\BulkSiteRequest::STATUS_CANCELLED,
        ],
    ];
    $activeStrip = collect($strip)->firstWhere('active', true);
    $chipLabels = [];
    if ($q !== '') {
        $chipLabels['q'] = 'Search: '.$q;
    }
    if ($selectedStatus !== \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER) {
        $chipLabels['status'] = $activeStrip['label'] ?? \App\Models\BulkSiteRequest::statusLabelFor($selectedStatus);
    }
    if ($mine) {
        $chipLabels['mine'] = 'Mine';
    }
    if (! $mine && $handlerFilter !== '') {
        $chipLabels['handler'] = $handlerFilter === 'unclaimed'
            ? 'Unclaimed'
            : ('Handler: '.($staffAssignees->firstWhere('id', (int) $handlerFilter)?->name ?? '#'.$handlerFilter));
    }
    if (($listQuery['sort'] ?? '') !== '') {
        $chipLabels['sort'] = 'Sort: '.$sort;
    }
    $badgeClass = function (string $tone): string {
        return match ($tone) {
            'marketer' => 'bulk-status-badge bulk-status-badge--marketer',
            'publisher' => 'bulk-status-badge bulk-status-badge--publisher',
            'staff' => 'bulk-status-badge bulk-status-badge--staff',
            'finished' => 'bulk-status-badge bulk-status-badge--finished',
            'cancelled' => 'bulk-status-badge bulk-status-badge--cancelled',
            default => 'bulk-status-badge bulk-status-badge--neutral',
        };
    };
@endphp
<div class="container-fluid bulk-request-index">
    <div class="mb-3">
        <h3 class="mb-1">Bulk site requests</h3>
        <p class="text-muted small mb-0" data-bulk-index-help>
            Publishers send a list of URLs with prices. You fill the listing details, then either
            <strong>Publish ready</strong> (live, not verified) or <strong>Send for review</strong>
            (publisher Accepts to go live, or Edits for you to Activate).
            Age pills turn amber after {{ $slaAmberDays }} days and red after {{ $slaRedDays }} days.
            Automatic publisher reminders stay off unless an admin enables them in config.
        </p>
    </div>

    <div class="staff-sites-strip mb-3" aria-label="Bulk request queues">
        @foreach($strip as $cell)
            <a href="{{ $cell['url'] }}"
               class="staff-sites-strip__cell {{ $cell['active'] ? 'is-active' : '' }}{{ (int) $cell['count'] === 0 ? ' is-zero' : '' }}"
               @if($cell['active']) aria-current="page" @endif>
                <span class="staff-sites-strip__count">{{ number_format($cell['count']) }}</span>
                <span class="staff-sites-strip__label">{{ $cell['label'] }}</span>
            </a>
        @endforeach
    </div>
    @if($activeStrip)
        <p class="small text-muted mb-3">{{ $activeStrip['hint'] }}</p>
    @endif

    @if($handlerCounts !== [])
        <div class="bulk-handler-counts mb-3" aria-label="Open requests by handler">
            @foreach($handlerCounts as $row)
                @php
                    $handlerKey = $row['id'] === null ? 'unclaimed' : (string) $row['id'];
                    $handlerActive = ! $mine && $handlerFilter === $handlerKey;
                    $handlerUrl = staff_route('bulk-site-requests.index', array_filter([
                        'q' => $q !== '' ? $q : null,
                        'status' => $selectedStatus !== \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER ? $selectedStatus : null,
                        'handler' => $handlerActive ? null : $handlerKey,
                    ]));
                @endphp
                <a href="{{ $handlerUrl }}"
                   class="bulk-handler-count{{ $handlerActive ? ' is-active' : '' }}"
                   @if($handlerActive) aria-current="page" @endif>
                    {{ $row['name'] }}
                    <span>{{ number_format($row['count']) }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if($chipLabels !== [])
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            @foreach($chipLabels as $key => $label)
                @php
                    $without = $listQuery;
                    unset($without[$key]);
                @endphp
                <a href="{{ staff_route('bulk-site-requests.index', $without) }}" class="badge rounded-pill text-bg-light border text-decoration-none">{{ $label }} ×</a>
            @endforeach
            <a href="{{ staff_route('bulk-site-requests.index') }}" class="small">Clear filters</a>
        </div>
    @endif

    <form method="GET" action="{{ staff_route('bulk-site-requests.index') }}" class="admin-deposits-filters staff-site-filters mb-3 d-flex flex-wrap align-items-end gap-2" data-bulk-index-filters>
        <div>
            <x-slb-search-field
                name="q"
                id="bulkRequestSearch"
                :value="$q"
                placeholder="Request #, publisher, or domain"
                label="Search"
                label-class="form-label"
                input-class="form-control"
                mode="form-live"
                title="Results update as you type. Two characters or Enter."
            />
        </div>
        <div>
            <label class="form-label" for="bulkRequestStatus">Status</label>
            <select name="status" id="bulkRequestStatus" class="form-select">
                <option value="{{ \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER }}" @selected($selectedStatus === \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER)>Waiting on you</option>
                <option value="{{ \App\Support\MarketingOpsQueues::FILTER_WAITING_PUBLISHER }}" @selected($selectedStatus === \App\Support\MarketingOpsQueues::FILTER_WAITING_PUBLISHER)>Waiting on publisher</option>
                <option value="{{ \App\Support\MarketingOpsQueues::FILTER_OVERDUE }}" @selected($selectedStatus === \App\Support\MarketingOpsQueues::FILTER_OVERDUE)>Overdue</option>
                <option value="all" @selected($selectedStatus === 'all')>All open</option>
                @foreach(['requested','sheet_sent','seeded'] as $s)
                    <option value="{{ $s }}" @selected($selectedStatus === $s)>{{ \App\Models\BulkSiteRequest::statusLabelFor($s) }}</option>
                @endforeach
                <option value="{{ \App\Models\BulkSiteRequest::STATUS_COMPLETED }}" @selected($selectedStatus === \App\Models\BulkSiteRequest::STATUS_COMPLETED)>Finished</option>
                <option value="{{ \App\Models\BulkSiteRequest::STATUS_CANCELLED }}" @selected($selectedStatus === \App\Models\BulkSiteRequest::STATUS_CANCELLED)>Cancelled</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="bulkRequestSort">Sort</label>
            <select name="sort" id="bulkRequestSort" class="form-select">
                <option value="oldest" @selected($sort === 'oldest')>Oldest waiting</option>
                <option value="newest" @selected($sort === 'newest')>Newest</option>
                <option value="pending" @selected($sort === 'pending')>Most pending rows</option>
                <option value="pending_asc" @selected($sort === 'pending_asc')>Fewest pending rows</option>
                <option value="est_desc" @selected($sort === 'est_desc')>Highest estimate</option>
                <option value="est" @selected($sort === 'est')>Lowest estimate</option>
                <option value="sites_desc" @selected($sort === 'sites_desc')>Most sites</option>
                <option value="sites" @selected($sort === 'sites')>Fewest sites</option>
            </select>
        </div>
        <input type="hidden" name="mine" value="{{ $mine ? '1' : '' }}" data-bulk-mine-input>
        @if(! $mine && $handlerFilter !== '')
            <input type="hidden" name="handler" value="{{ $handlerFilter }}">
        @endif
        <button type="button"
                class="btn btn-sm {{ $mine ? 'btn-primary' : 'btn-outline-secondary' }}"
                data-bulk-mine-toggle
                aria-pressed="{{ $mine ? 'true' : 'false' }}">
            Mine
        </button>
        <button type="submit" class="btn btn-primary">Search</button>
        @if(!empty($filtersActive))
            <a href="{{ staff_route('bulk-site-requests.index') }}" class="btn btn-outline-secondary">Reset</a>
        @endif
    </form>

    @if(auth()->user()?->isAdmin())
    <div class="d-flex flex-wrap gap-2 mb-2 align-items-center" data-bulk-toolbar>
        <button type="submit" form="adminBulkRequestsClaimForm" class="btn btn-sm btn-outline-primary"
                data-slb-confirm="Claim selected bulk requests?"
                data-slb-confirm-title="Claim requests?"
                data-slb-confirm-text="Claim">Claim selected</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk-open-assign>Assign selected…</button>
        <button type="submit" form="adminBulkRequestsRemindForm" class="btn btn-sm btn-outline-secondary"
                data-slb-confirm="Send a reminder email to the publisher on each selected row that is waiting on them?"
                data-slb-confirm-title="Remind publishers?"
                data-slb-confirm-text="Remind">Remind selected</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-bulk-open-cancel>Cancel selected…</button>
        <a href="{{ staff_route('bulk-site-requests.index', $listQuery + ['export' => 'csv']) }}" class="btn btn-sm btn-outline-secondary">Export CSV</a>
        <span class="small text-muted">Select rows for bulk actions. Cancelled rows are skipped. CSV has no prices.</span>
    </div>

    <form method="POST" action="{{ staff_route('bulk-site-requests.bulk-assign') }}" id="adminBulkRequestsAssignForm" class="d-none" data-bulk-assign-form>
        @csrf
        <div class="d-flex flex-wrap gap-2 mb-2 align-items-end">
            <div>
                <label class="form-label" for="bulkAssignHandler">Assign selected to</label>
                <select name="handled_by" id="bulkAssignHandler" class="form-select form-select-sm">
                    <option value="">Unclaimed</option>
                    @foreach($staffAssignees as $staff)
                        <option value="{{ $staff->id }}" @selected((int) $staff->id === (int) auth()->id())>{{ $staff->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-primary"
                    data-slb-confirm="Assign the selected requests to this person?"
                    data-slb-confirm-title="Assign requests?"
                    data-slb-confirm-text="Assign">Assign</button>
        </div>
    </form>

    <form method="POST" action="{{ staff_route('bulk-site-requests.bulk-cancel') }}" id="adminBulkRequestsCancelForm" class="d-none" data-bulk-cancel-form>
        @csrf
        <div class="d-flex flex-wrap gap-2 mb-2 align-items-end">
            <div class="flex-grow-1" style="min-width:16rem;">
                <label class="form-label" for="bulkCancelReason">Cancel reason (publisher is emailed)</label>
                <input type="text" name="reason" id="bulkCancelReason" class="form-control form-control-sm" minlength="10" maxlength="1000" placeholder="At least 10 characters">
            </div>
            <button type="submit" class="btn btn-sm btn-danger"
                    data-slb-confirm="Cancel the selected requests? The publisher is notified and history is kept."
                    data-slb-confirm-title="Cancel requests?"
                    data-slb-confirm-text="Cancel">Cancel selected</button>
        </div>
    </form>

    <form method="POST" action="{{ staff_route('bulk-site-requests.bulk-claim') }}" id="adminBulkRequestsClaimForm">
        @csrf
    </form>
    <form method="POST" action="{{ staff_route('bulk-site-requests.bulk-remind') }}" id="adminBulkRequestsRemindForm">
        @csrf
    </form>
    @else
    <div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
        <a href="{{ staff_route('bulk-site-requests.index', $listQuery + ['export' => 'csv']) }}" class="btn btn-sm btn-outline-secondary">Export CSV</a>
        <span class="small text-muted">View only — ask an admin to change status. CSV has no prices.</span>
    </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle admin-table-fit staff-queue-table bulk-request-index-table">
                <thead>
                    <tr>
                        <th class="admin-num-col">
                            @if(auth()->user()?->isAdmin())
                                <input type="checkbox" class="form-check-input" id="adminBulkRequestsSelectAll" aria-label="Select all">
                            @endif
                        </th>
                        <th>Req #</th>
                        <th>Publisher</th>
                        <th>
                            <a href="{{ $sortUrl($estSortNext) }}" class="bulk-sort-link{{ in_array($sort, ['est', 'est_desc'], true) ? ' is-active' : '' }}">Est.</a>
                        </th>
                        <th class="bulk-status-col">Status</th>
                        <th>
                            <a href="{{ $sortUrl($sitesSortNext) }}" class="bulk-sort-link{{ in_array($sort, ['sites', 'sites_desc'], true) ? ' is-active' : '' }}">Sites</a>
                        </th>
                        <th>
                            <a href="{{ $sortUrl($pendingSortNext) }}" class="bulk-sort-link{{ in_array($sort, ['pending', 'pending_asc'], true) ? ' is-active' : '' }}">Pending to add</a>
                        </th>
                        <th class="d-none d-lg-table-cell">Awaiting details</th>
                        <th class="d-none d-lg-table-cell">Ready</th>
                        <th>
                            <a href="{{ $sortUrl($waitingSortNext) }}" class="bulk-sort-link{{ in_array($sort, ['oldest', 'newest'], true) ? ' is-active' : '' }}">Waiting</a>
                        </th>
                        <th>Handler</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requests as $req)
                        @php
                            $listedDays = $req->ageDays();
                            $slaTone = $req->slaTone();
                            $blockTip = $req->blocksNewBulkTooltip();
                            $staffAssignedCount = $req->cachedStaffAssignedCount();
                            $remindedAt = $lastReminded[$req->id] ?? null;
                            $progress = $req->progressCounts();
                            $progressPct = $progress['total'] > 0
                                ? (int) round(100 * $progress['done'] / $progress['total'])
                                : 0;
                        @endphp
                        <tr>
                            <td>
                                @if(auth()->user()?->isAdmin() && ! $req->isCancelled())
                                    <input type="checkbox" class="form-check-input js-bulk-request-row" name="ids[]" value="{{ $req->id }}" form="adminBulkRequestsClaimForm">
                                @endif
                            </td>
                            <td>
                                <a href="{{ staff_route('bulk-site-requests.show', $req) }}" class="fw-semibold text-decoration-none">#{{ $req->id }}</a>
                            </td>
                            <td>
                                @if(auth()->user()?->isAdmin() && $req->publisher)
                                    <a href="{{ route('admin.users.show', $req->publisher) }}" class="fw-semibold text-decoration-none">{{ $req->publisher->name }}</a>
                                @else
                                    <div class="fw-semibold">{{ $req->publisher?->name ?? '—' }}</div>
                                @endif
                                @if($req->publisher?->email)
                                    <div class="bulk-publisher-email">{{ $req->publisher->email }}</div>
                                @endif
                                @if($blockTip)
                                    <span class="badge text-bg-warning text-dark mt-1"
                                          title="{{ $blockTip['reason'] }} {{ $blockTip['clears'] }}"
                                          data-bs-toggle="tooltip"
                                          data-bs-title="{{ $blockTip['reason'] }} {{ $blockTip['clears'] }}">Blocks new bulk</span>
                                @endif
                                @if($staffAssignedCount > 0)
                                    <div class="small text-muted mt-1">Staff added {{ $staffAssignedCount }} {{ \Illuminate\Support\Str::plural('site', $staffAssignedCount) }}</div>
                                @endif
                            </td>
                            <td>{{ $req->estimated_count ?? '—' }}</td>
                            <td class="bulk-status-col">
                                <div class="d-flex flex-column align-items-start gap-1">
                                    <span class="{{ $badgeClass($req->statusBadgeTone()) }}">{{ $req->nextStepText() }}</span>
                                    @if($req->waitingOnPublisherSites())
                                        <span class="bulk-status-badge bulk-status-badge--publisher">Sites: waiting</span>
                                    @endif
                                    @if($staffAssignedCount > 0)
                                        <span class="bulk-status-badge bulk-status-badge--staff">Staff batch</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div>{{ $req->sites_count }}</div>
                                <div class="bulk-progress" title="{{ $progress['done'] }} of {{ $progress['total'] }} done">
                                    <div class="bulk-progress__bar" style="width: {{ $progressPct }}%"></div>
                                </div>
                                <div class="bulk-progress__label">{{ $progress['done'] }}/{{ $progress['total'] }}</div>
                            </td>
                            <td>{{ $req->pending_items_count }}</td>
                            <td class="d-none d-lg-table-cell">{{ $req->cachedAwaitingDetailsCount() }}</td>
                            <td class="d-none d-lg-table-cell">{{ (int) ($req->ready_count ?? 0) }}</td>
                            <td>
                                @if($listedDays === null)
                                    <span class="text-muted">—</span>
                                @else
                                    <span class="bulk-age-pill bulk-age-pill--{{ $slaTone }}"
                                          title="{{ \Illuminate\Support\Carbon::parse($req->created_at)->timezone(config('app.timezone'))->format('M j, Y') }}">
                                        {{ $listedDays === 0 ? 'Today' : $listedDays.'d' }}
                                    </span>
                                @endif
                            </td>
                            <td class="small">
                                @if(auth()->user()?->isAdmin())
                                    <form method="POST" action="{{ staff_route('bulk-site-requests.assign', $req->id) }}" class="bulk-assign-row">
                                        @csrf
                                        <label class="visually-hidden" for="bulkAssignRow{{ $req->id }}">Assign request #{{ $req->id }}</label>
                                        <select name="handled_by"
                                                id="bulkAssignRow{{ $req->id }}"
                                                class="form-select form-select-sm"
                                                @disabled($req->isCancelled())
                                                onchange="this.form.requestSubmit()">
                                            <option value="" @selected(! $req->handled_by)>Unclaimed</option>
                                            @foreach($staffAssignees as $staff)
                                                <option value="{{ $staff->id }}" @selected((int) $req->handled_by === (int) $staff->id)>{{ $staff->name }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @else
                                    {{ $req->handler?->name ?? 'Unclaimed' }}
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="bulk-row-actions">
                                    @if(auth()->user()?->isAdmin())
                                        @if($req->canPublishReady())
                                            <form method="POST" action="{{ staff_route('bulk-site-requests.publish-now', $req->id) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-success"
                                                        data-slb-confirm="Publish the ready sites on this request now? They go live unverified."
                                                        data-slb-confirm-title="Publish ready sites?"
                                                        data-slb-confirm-text="Publish ready">Publish ready</button>
                                            </form>
                                        @endif
                                        @if($req->canSendForReview())
                                            <a href="{{ staff_route('bulk-site-requests.show', $req) }}#done" class="btn btn-sm btn-outline-primary">Send for review</a>
                                        @endif
                                        @if($req->canRemindPublisher())
                                            <form method="POST" action="{{ staff_route('bulk-site-requests.remind', $req->id) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                        data-slb-confirm="Send a reminder to the publisher?"
                                                        data-slb-confirm-title="Remind publisher?"
                                                        data-slb-confirm-text="Remind">Remind</button>
                                            </form>
                                            @if($remindedAt)
                                                <div class="bulk-last-reminded">Last reminded {{ $remindedAt->timezone(config('app.timezone'))->format('M j') }}</div>
                                            @endif
                                        @endif
                                    @endif
                                    <a href="{{ staff_route('bulk-site-requests.show', $req) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="text-center text-muted py-4">
                                @if(!empty($filtersActive))
                                    <div class="mb-2">No requests match this filter.</div>
                                    <a href="{{ staff_route('bulk-site-requests.index') }}" class="btn btn-outline-secondary">Reset filter</a>
                                @elseif($selectedStatus === \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER)
                                    Nothing waiting on you.
                                @elseif($selectedStatus === \App\Support\MarketingOpsQueues::FILTER_OVERDUE)
                                    No overdue batches.
                                @elseif($selectedStatus === \App\Models\BulkSiteRequest::STATUS_COMPLETED)
                                    No finished batches.
                                @elseif($selectedStatus === \App\Models\BulkSiteRequest::STATUS_CANCELLED)
                                    No cancelled batches.
                                @else
                                    No bulk requests yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $requests->links() }}</div>
</div>
<script>
(function () {
    var selectAll = document.getElementById('adminBulkRequestsSelectAll');
    var rowBoxes = function () {
        return Array.prototype.slice.call(document.querySelectorAll('.js-bulk-request-row'));
    };
    var syncHiddenIds = function (form) {
        if (!form) return;
        form.querySelectorAll('input[name="ids[]"][data-bulk-cloned]').forEach(function (el) {
            el.remove();
        });
        rowBoxes().forEach(function (box) {
            if (!box.checked) return;
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'ids[]';
            hidden.value = box.value;
            hidden.setAttribute('data-bulk-cloned', '1');
            form.appendChild(hidden);
        });
    };

    selectAll && selectAll.addEventListener('change', function () {
        rowBoxes().forEach(function (box) {
            box.checked = selectAll.checked;
        });
    });

    ['adminBulkRequestsClaimForm', 'adminBulkRequestsRemindForm', 'adminBulkRequestsAssignForm', 'adminBulkRequestsCancelForm'].forEach(function (id) {
        var form = document.getElementById(id);
        form && form.addEventListener('submit', function () {
            syncHiddenIds(form);
        });
    });

    var mineInput = document.querySelector('[data-bulk-mine-input]');
    var mineToggle = document.querySelector('[data-bulk-mine-toggle]');
    var filterForm = document.querySelector('[data-bulk-index-filters]');
    mineToggle && mineToggle.addEventListener('click', function () {
        if (!mineInput || !filterForm) return;
        var on = mineInput.value === '1';
        mineInput.value = on ? '' : '1';
        if (typeof filterForm.requestSubmit === 'function') filterForm.requestSubmit();
        else filterForm.submit();
    });

    var assignForm = document.querySelector('[data-bulk-assign-form]');
    var cancelForm = document.querySelector('[data-bulk-cancel-form]');
    document.querySelector('[data-bulk-open-assign]')?.addEventListener('click', function () {
        assignForm && assignForm.classList.toggle('d-none');
        cancelForm && cancelForm.classList.add('d-none');
    });
    document.querySelector('[data-bulk-open-cancel]')?.addEventListener('click', function () {
        cancelForm && cancelForm.classList.toggle('d-none');
        assignForm && assignForm.classList.add('d-none');
    });

    if (window.bootstrap) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            try { new window.bootstrap.Tooltip(el); } catch (err) {}
        });
    }
})();
</script>
@endsection
