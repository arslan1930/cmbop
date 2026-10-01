@php
    $chipDefs = [
        'all' => 'All',
        'available' => 'Approved',
        'evaluating' => 'Evaluating',
        'in_progress' => 'In progress',
        'needs_fix' => 'Needs corrections',
        'completed' => 'Completed/LIVE',
        'archived' => 'Archived',
        'expired' => 'Expired',
    ];
    $chipTitles = [
        'available' => 'Checkout-ready (file, market, rights, and a valid link pair).',
        'all' => 'Unused expired articles are on the Expired chip, not here.',
        'expired' => 'Unused expired articles hidden from All.',
    ];
    $filterBase = $filterQuery ?? [];
    $chipUrl = function (string $availability) use ($filterBase) {
        $params = $filterBase;
        if ($availability === 'all') {
            unset($params['availability']);
        } else {
            $params['availability'] = $availability;
        }
        unset($params['page']);

        return route('admin.content-library.index', $params);
    };
    $availabilityBadge = function ($submission): array {
        return match ($submission->libraryAvailability()) {
            'available' => ['success', 'Approved'],
            'evaluating' => ['info', 'Evaluating'],
            'in_progress' => ['primary', 'In progress'],
            'published' => ['success', 'Completed/LIVE'],
            'needs_fix' => ['danger', 'Needs corrections'],
            'expired' => ['warning', 'Expired'],
            'archived' => ['dark', 'Archived'],
            default => ['secondary', 'Pending'],
        };
    };
    $bulkLimit = (int) ($bulkLimit ?? 50);
@endphp

<nav class="d-flex flex-wrap gap-2 mb-2" aria-label="Library availability filter" id="adminLibraryChips">
    @foreach($chipDefs as $key => $label)
        @php $count = (int) ($availabilityCounts[$key] ?? 0); @endphp
        <a href="{{ $chipUrl($key) }}"
           class="btn btn-sm {{ $availability === $key ? 'btn-primary' : 'btn-outline-secondary' }}"
           @if(! empty($chipTitles[$key])) title="{{ $chipTitles[$key] }}" @endif>
            {{ $label }} ({{ $count }})
        </a>
    @endforeach
</nav>
<p class="small text-muted mb-3">Approved means checkout-ready, not only that a scan passed. All omits unused expired articles — use Expired.</p>

<form method="POST" action="{{ route('admin.content-library.bulk-archive') }}" id="adminLibraryBulkForm">
    @csrf
    <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="submit" class="btn btn-sm btn-outline-secondary"
                formaction="{{ route('admin.content-library.bulk-retry') }}"
                data-slb-confirm="Re-evaluate the selected articles?"
                data-slb-confirm-title="Re-evaluate selected?"
                data-slb-confirm-text="Re-evaluate">
            Re-evaluate selected
        </button>
        <button type="submit" class="btn btn-sm btn-outline-secondary"
                data-slb-confirm="Archive the selected articles? Advertisers can still restore them."
                data-slb-confirm-title="Archive selected?"
                data-slb-confirm-text="Archive">
            Archive selected
        </button>
        <button type="submit" class="btn btn-sm btn-outline-secondary"
                formaction="{{ route('admin.content-library.bulk-restore') }}"
                data-slb-confirm="Restore the selected archived articles?"
                data-slb-confirm-title="Restore selected?"
                data-slb-confirm-text="Restore">
            Restore selected
        </button>
        <a href="{{ route('admin.content-library.export', $filterQuery ?? []) }}" class="btn btn-sm btn-outline-secondary ms-auto">
            Export CSV
        </a>
        <span class="small text-muted align-self-center">Up to {{ $bulkLimit }} at a time · first {{ (int) ($exportLimit ?? 2000) }} rows</span>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:2rem;">
                            <input type="checkbox" id="adminLibrarySelectPage" class="form-check-input" aria-label="Select articles on this page">
                        </th>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Advertiser</th>
                        <th>Market</th>
                        <th>Status</th>
                        <th>Scores</th>
                        <th>Expires</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($submissions as $submission)
                    @php
                        [$badgeTone, $badgeLabel] = $availabilityBadge($submission);
                        $title = $submission->title ?: $submission->original_filename;
                        $showFilename = filled($submission->original_filename)
                            && strcasecmp((string) $submission->title, (string) $submission->original_filename) !== 0
                            && filled($submission->title);
                        $market = trim(strtoupper(implode('/', array_filter([
                            $submission->country,
                            $submission->language,
                        ]))));
                        $libraryOrder = $submission->libraryOrder();
                        $orderLabel = $libraryOrder?->order_number
                            ?: ($libraryOrder ? '#'.$libraryOrder->id : null);
                        $placementSite = $submission->libraryPlacementItem()?->site?->site_name
                            ?: $submission->orderItem?->site?->site_name;
                        $fileMissing = $submission->hasStoredFile() && ! ($submission->file_on_disk ?? true);
                    @endphp
                    <tr>
                        <td>
                            <input type="checkbox" name="ids[]" value="{{ $submission->id }}" class="form-check-input" aria-label="Select article #{{ $submission->id }}">
                        </td>
                        <td class="text-muted small">#{{ $submission->id }}</td>
                        <td>
                            <div class="fw-semibold">{{ $title }}</div>
                            @if($showFilename)
                                <div class="small text-muted">{{ $submission->original_filename }}</div>
                            @endif
                            @if($fileMissing)
                                <span class="badge text-bg-warning text-dark">File missing on disk</span>
                            @endif
                            @if($orderLabel)
                                <div class="small text-muted">
                                    Order
                                    @if($libraryOrder)
                                        <a href="{{ route('admin.orders.show', $libraryOrder->id) }}">{{ $orderLabel }}</a>
                                    @else
                                        {{ $orderLabel }}
                                    @endif
                                    @if($placementSite)
                                        · {{ $placementSite }}
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td>
                            @if($submission->user)
                                <div>
                                    <a href="{{ $submission->user->adminShowUrl() }}">
                                        {{ $submission->user->name ?: 'User #'.$submission->user->id }}
                                    </a>
                                </div>
                                <div class="small text-muted">{{ $submission->user->email }}</div>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($market !== '')
                                <span class="badge text-bg-light">{{ $market }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge text-bg-{{ $badgeTone }}">{{ $badgeLabel }}</span>
                        </td>
                        <td class="small text-muted">
                            @if($submission->evaluated_at)
                                U {{ $submission->uniqueness_score ?? '—' }}% · Q {{ $submission->quality_score ?? '—' }}%
                            @else
                                —
                            @endif
                        </td>
                        <td class="small text-muted">
                            {{ optional($submission->expires_at)->format('Y-m-d') ?: '—' }}
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.content-library.show', array_merge(['submission' => $submission], $filterQuery)) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">No articles match these filters.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($submissions->hasPages())
            <div class="card-footer bg-white">{{ $submissions->links() }}</div>
        @endif
    </div>
</form>
