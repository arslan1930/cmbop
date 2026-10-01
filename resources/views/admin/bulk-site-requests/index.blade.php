@extends(staff_layout())

@section('content')
@php
    $selectedStatus = $status ?? \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER;
    $q = $q ?? '';
    $mine = ! empty($mine);
    $sort = $sort ?? '';
    $listQuery = array_filter([
        'q' => $q !== '' ? $q : null,
        'status' => $selectedStatus !== \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER ? $selectedStatus : null,
        'mine' => $mine ? 1 : null,
        'sort' => in_array($sort, ['newest', 'pending'], true)
            || ($sort === 'oldest' && $selectedStatus !== \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER)
            ? $sort
            : null,
    ], static fn ($value) => $value !== null && $value !== '');
    $strip = [
        [
            'key' => 'you',
            'label' => 'Waiting on you',
            'count' => (int) ($waitingOnYouCount ?? 0),
            'url' => staff_route('bulk-site-requests.index', array_filter(['q' => $q !== '' ? $q : null])),
            'hint' => 'URL + price rows you can fill and Done.',
            'active' => $selectedStatus === \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER,
        ],
        [
            'key' => 'publisher',
            'label' => 'Waiting on publisher',
            'count' => (int) ($waitingOnPublisherCount ?? 0),
            'url' => staff_route('bulk-site-requests.index', array_filter(['status' => \App\Support\MarketingOpsQueues::FILTER_WAITING_PUBLISHER, 'q' => $q !== '' ? $q : null])),
            'hint' => 'Drafts still with the publisher, including staff-added invites waiting on Accept. Not staff work yet.',
            'active' => $selectedStatus === \App\Support\MarketingOpsQueues::FILTER_WAITING_PUBLISHER,
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
    if (($listQuery['sort'] ?? '') !== '') {
        $chipLabels['sort'] = 'Sort: '.$sort;
    }
@endphp
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h3 class="mb-1">Bulk site requests</h3>
            <p class="text-muted small mb-0">
                Publishers submit <strong>URL + price</strong>. On Done, <strong>Publish now</strong> puts the filled sites live (active, not verified). <strong>Send for review</strong> lets the publisher Accept (goes live) or Edit (then you Activate). Finished requests leave Waiting on you.
            </p>
        </div>
        <a href="{{ staff_route('bulk-site-requests.index') }}" class="badge text-bg-secondary align-self-center text-decoration-none" data-bulk-waiting-on-you>
            {{ $waitingOnYouCount }} waiting on you
        </a>
    </div>

    <div class="staff-sites-strip mb-3" aria-label="Bulk request queues">
        @foreach($strip as $cell)
            <a href="{{ $cell['url'] }}"
               class="staff-sites-strip__cell {{ $cell['active'] ? 'is-active' : '' }}"
               @if($cell['active']) aria-current="page" @endif>
                <span class="staff-sites-strip__count">{{ number_format($cell['count']) }}</span>
                <span class="staff-sites-strip__label">{{ $cell['label'] }}</span>
            </a>
        @endforeach
    </div>
    @if($activeStrip)
        <p class="small text-muted mb-3">{{ $activeStrip['hint'] }}</p>
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

    <form method="GET" class="admin-deposits-filters staff-site-filters mb-3 d-flex flex-wrap align-items-end gap-2" data-bulk-index-filters>
        <div>
            <x-slb-search-field
                name="q"
                id="bulkRequestSearch"
                :value="$q"
                placeholder="Request #, publisher, email, or domain"
                label="Search"
                label-class="form-label"
                input-class="form-control"
            />
        </div>
        <div>
            <label class="form-label" for="bulkRequestStatus">Status</label>
            <select name="status" id="bulkRequestStatus" class="form-select">
                <option value="{{ \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER }}" @selected($selectedStatus === \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER)>Waiting on you</option>
                <option value="{{ \App\Support\MarketingOpsQueues::FILTER_WAITING_PUBLISHER }}" @selected($selectedStatus === \App\Support\MarketingOpsQueues::FILTER_WAITING_PUBLISHER)>Waiting on publisher</option>
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
            </select>
        </div>
        <label class="form-check small mb-1">
            <input class="form-check-input" type="checkbox" name="mine" value="1" @checked($mine)>
            Mine
        </label>
        <button type="submit" class="btn btn-primary">Search</button>
        @if(!empty($filtersActive))
            <a href="{{ staff_route('bulk-site-requests.index') }}" class="btn btn-outline-secondary">Reset</a>
        @endif
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle admin-table-fit staff-queue-table bulk-request-index-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Publisher</th>
                        <th>Est.</th>
                        <th>Status</th>
                        <th>Sites</th>
                        <th>Pending to add</th>
                        <th class="d-none d-lg-table-cell">Awaiting details</th>
                        <th class="d-none d-lg-table-cell">Ready</th>
                        <th>Waiting</th>
                        <th>Handler</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requests as $req)
                        @php
                            $listedAt = $req->created_at;
                            $listedDays = $listedAt instanceof \DateTimeInterface
                                ? (int) \Illuminate\Support\Carbon::parse($listedAt)->diffInDays(now())
                                : null;
                            $listedClass = 'text-muted';
                            if ($listedDays !== null && $listedDays >= 21) {
                                $listedClass = 'text-danger fw-semibold';
                            } elseif ($listedDays !== null && $listedDays >= 7) {
                                $listedClass = 'text-warning fw-semibold';
                            }
                            $waitingPublisher = ((int) ($req->awaiting_details_count ?? 0)
                                + (int) ($req->reviewing_count ?? 0)
                                + (int) ($req->pending_accept_count ?? 0)) > 0;
                            $staffAssignedCount = (int) ($req->staff_assigned_count ?? 0);
                        @endphp
                        <tr>
                            <td>{{ $req->id }}</td>
                            <td>
                                @if(auth()->user()?->isAdmin() && $req->publisher)
                                    <a href="{{ route('admin.users.show', $req->publisher) }}" class="fw-semibold text-decoration-none">{{ $req->publisher->name }}</a>
                                @else
                                    <div class="fw-semibold">{{ $req->publisher?->name ?? '—' }}</div>
                                @endif
                                <div class="small text-muted">{{ $req->publisher?->email ?? '' }}</div>
                                @if($req->blocksPublisherNewBulk())
                                    <span class="badge text-bg-warning text-dark">Blocks new bulk</span>
                                @endif
                                @if($staffAssignedCount > 0)
                                    <div class="small text-muted mt-1">Staff added {{ $staffAssignedCount }} {{ \Illuminate\Support\Str::plural('site', $staffAssignedCount) }}</div>
                                @endif
                            </td>
                            <td>{{ $req->estimated_count ?? '—' }}</td>
                            <td>
                                <span class="badge text-bg-light border">{{ $req->statusLabel() }}</span>
                                @if($staffAssignedCount > 0)
                                    <span class="badge text-bg-info ms-1">Staff batch</span>
                                @endif
                            </td>
                            <td>{{ $req->sites_count }}</td>
                            <td>{{ $req->pending_items_count }}</td>
                            <td class="d-none d-lg-table-cell">{{ (int) ($req->awaiting_details_count ?? 0) }}</td>
                            <td class="d-none d-lg-table-cell">{{ (int) ($req->ready_count ?? 0) }}</td>
                            <td class="small">
                                @if($listedDays === null)
                                    <span class="text-muted">—</span>
                                @else
                                    <span class="{{ $listedClass }}" title="{{ \Illuminate\Support\Carbon::parse($listedAt)->timezone(config('app.timezone'))->format('M j, Y') }}">{{ $listedDays === 0 ? 'Today' : $listedDays.'d' }}</span>
                                @endif
                            </td>
                            <td class="small">{{ $req->handler?->name ?? 'Unclaimed' }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ staff_route('bulk-site-requests.show', $req) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                @if($waitingPublisher)
                                    <a href="{{ staff_route('sites.index', array_filter(['waiting_on_publisher' => 1, 'q' => $req->publisher?->email])) }}" class="btn btn-sm btn-outline-secondary">Sites: waiting</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">
                                @if(!empty($filtersActive))
                                    <div class="mb-2">No requests match this filter.</div>
                                    <a href="{{ staff_route('bulk-site-requests.index') }}" class="btn btn-outline-secondary">Reset filter</a>
                                @elseif($selectedStatus === \App\Support\MarketingOpsQueues::FILTER_NEEDS_MARKETER)
                                    Nothing waiting on you.
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
@endsection
