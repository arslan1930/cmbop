@php
    $filterBase = $libraryFilterBase ?? [
        'status' => $statusFilter ?? 'all',
        'availability' => $availabilityFilter ?? 'all',
        'language' => $languageFilter ?? 'all',
        'country' => $countryFilter ?? 'all',
        'q' => $searchQuery ?? '',
    ];
    $libraryRoute = function (array $overrides = []) use ($filterBase) {
        $params = array_merge($filterBase, $overrides);
        if (($params['q'] ?? '') === '') {
            unset($params['q']);
        }
        return route('advertiser.content-library', $params, false);
    };
    $statusLabels = [
        'available' => 'Approved',
        'evaluating' => 'Processing',
        'in_progress' => 'Processing',
        'published' => 'Completed/LIVE',
        'needs_fix' => 'Needs corrections',
        'expired' => 'Expired',
        'archived' => 'Archived',
        'unavailable' => 'Pending',
    ];
    $moderationCounts = $moderationCounts ?? [
        'all' => 0,
        'approved' => 0,
        'rejected' => 0,
        'needs_fix' => 0,
    ];
    $availabilityCounts = $availabilityCounts ?? [
        'all' => 0,
        'available' => 0,
        'evaluating' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'expired' => 0,
        'archived' => 0,
        'needs_fix' => 0,
    ];
    $uploadsEnabled = $uploadsEnabled ?? true;
    // Status strip: Approved · Processing · Needs corrections · Completed/LIVE · Archived · Expired
    // Processing is the existing in_progress bucket (ordered, not live) — not a new evaluating tab.
    $libraryStatusChips = [
        'approved' => [
            'label' => 'Approved',
            'count' => (int) ($availabilityCounts['available'] ?? 0),
            'params' => ['status' => 'approved', 'availability' => 'available'],
        ],
        'processing' => [
            'label' => 'Processing',
            'count' => (int) ($availabilityCounts['in_progress'] ?? 0),
            'params' => ['status' => 'all', 'availability' => 'in_progress'],
        ],
        'needs_fix' => [
            'label' => 'Needs corrections',
            'count' => (int) ($availabilityCounts['needs_fix'] ?? 0),
            'params' => ['status' => 'all', 'availability' => 'needs_fix'],
        ],
        'completed' => [
            'label' => 'Completed/LIVE',
            'count' => (int) ($availabilityCounts['completed'] ?? 0),
            'params' => ['status' => 'all', 'availability' => 'completed'],
        ],
        'archived' => [
            'label' => 'Archived',
            'count' => (int) ($availabilityCounts['archived'] ?? 0),
            'params' => ['status' => 'all', 'availability' => 'archived'],
        ],
        'expired' => [
            'label' => 'Expired',
            'count' => (int) ($availabilityCounts['expired'] ?? 0),
            'params' => ['status' => 'all', 'availability' => 'expired'],
        ],
    ];
    $activeLibraryChip = 'approved';
    if (($availabilityFilter ?? 'all') === 'completed') {
        $activeLibraryChip = 'completed';
    } elseif (($availabilityFilter ?? 'all') === 'in_progress') {
        $activeLibraryChip = 'processing';
    } elseif (($availabilityFilter ?? 'all') === 'needs_fix'
        || ($statusFilter ?? 'all') === 'rejected') {
        $activeLibraryChip = 'needs_fix';
    } elseif (($availabilityFilter ?? 'all') === 'archived') {
        $activeLibraryChip = 'archived';
    } elseif (($availabilityFilter ?? 'all') === 'expired') {
        $activeLibraryChip = 'expired';
    } elseif (($availabilityFilter ?? 'all') === 'available' || ($statusFilter ?? 'all') === 'approved') {
        $activeLibraryChip = 'approved';
    }
    $libraryStatusDisplay = function (string $availability, string $moderationStatus = '') use ($statusLabels): array {
        $category = match ($availability) {
            'published' => 'completed',
            'needs_fix' => 'needs_fix',
            'in_progress' => 'processing',
            'available' => 'approved',
            'evaluating' => 'processing',
            'expired' => 'expired',
            'archived' => 'archived',
            default => 'pending',
        };
        $label = match ($category) {
            'completed' => 'Completed/LIVE',
            'needs_fix' => 'Needs corrections',
            'approved' => 'Approved',
            'processing' => 'Processing',
            'expired' => 'Expired',
            'archived' => 'Archived',
            default => ($moderationStatus === 'pending' || $moderationStatus === 'processing')
                ? 'Processing'
                : ($statusLabels[$availability] ?? 'Pending'),
        };

        return ['category' => $category, 'label' => $label];
    };
    $submissionRows = $submissions ?? collect();
    $showExpiryPolicyNote = $activeLibraryChip === 'approved'
        && collect($submissionRows instanceof \Illuminate\Contracts\Pagination\Paginator
            ? $submissionRows->items()
            : $submissionRows
        )->contains(fn ($row) => filled($row->expires_at ?? null));
    $libraryCountryNames = collect($countries ?? [])->mapWithKeys(function ($row) {
        $code = strtolower(trim((string) data_get($row, 'code')));
        $name = trim((string) data_get($row, 'name'));

        return ($code !== '' && $name !== '') ? [$code => $name] : [];
    });
    $libraryLanguageNames = collect($languages ?? [])->mapWithKeys(function ($row) {
        $code = strtolower(trim((string) data_get($row, 'code')));
        $name = trim((string) data_get($row, 'name'));

        return ($code !== '' && $name !== '') ? [$code => $name] : [];
    });
    $libraryMarketLabel = function (?string $country, ?string $language) use ($libraryCountryNames, $libraryLanguageNames): array {
        $countryCode = strtolower(trim((string) $country));
        $languageCode = strtolower(trim((string) $language));
        $codes = ($countryCode === '' && $languageCode === '')
            ? '—'
            : strtoupper($countryCode).'/'.strtoupper($languageCode);
        $countryName = $countryCode !== '' ? $libraryCountryNames->get($countryCode) : null;
        $languageName = $languageCode !== '' ? $libraryLanguageNames->get($languageCode) : null;
        if (filled($countryName) && filled($languageName)) {
            return ['label' => $countryName.' · '.$languageName, 'codes' => $codes];
        }

        return ['label' => $codes, 'codes' => $codes];
    };
@endphp

    <nav class="library-status-row" aria-label="Library status filter">
        @foreach($libraryStatusChips as $key => $chip)
            @php
                $chipCount = (int) ($chip['count'] ?? 0);
                $chipActive = $activeLibraryChip === $key;
            @endphp
            <a href="{{ $libraryRoute($chip['params']) }}"
               class="library-status-box library-status-box--{{ $key }} @if($chipActive) is-active @endif"
               @if($chipActive) aria-current="page" @endif
               aria-label="{{ $chip['label'] }}, {{ $chipCount }} {{ $chipCount === 1 ? 'article' : 'articles' }}">
                <span class="library-status-box__main">
                    <span class="library-status-box__label">
                        <span>{{ $chip['label'] }}</span>
                        @if($key === 'processing' && $chipCount > 0)
                            <span class="library-status-sweep" aria-hidden="true"></span>
                        @endif
                    </span>
                </span>
                <span class="mod-count{{ $chipCount === 0 ? ' is-zero' : '' }}">{{ $chipCount }}</span>
            </a>
        @endforeach
    </nav>

    @if($showExpiryPolicyNote)
        <p class="library-table-note" role="note">Unused originals are removed after expiry; preview stays.</p>
    @endif

    <div class="library-table border shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>Market</th>
                        <th>Status</th>
                        <th>
                            <span class="library-scores-head">
                                Uniqueness · Quality
                                <x-glass-tip
                                    title="Advisory scores"
                                    body="Uniqueness and quality are advisory. Approved articles can still be ordered even when a score is below the warn threshold. Policy and clear language mismatches can block approval."
                                    label="About scores"
                                    placement="bottom"
                                />
                            </span>
                        </th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($submissions as $submission)
                    @php
                        $availability = $submission->libraryAvailability();
                        $placement = $submission->libraryPlacementItem();
                        $libraryOrder = $submission->libraryOrder();
                        $rawLiveUrl = $submission->liveUrl();
                        $liveUrl = $rawLiveUrl ? safe_external_url($rawLiveUrl, '') : '';
                        $liveUrl = $liveUrl !== '' ? $liveUrl : null;
                        $siteName = $placement?->site_name
                            ?: $placement?->site?->site_name
                            ?: null;
                        $publishedDateLabel = null;
                        try {
                            $publishedAt = $placement?->live_url_submitted_at
                                ?: ($placement?->hasLiveUrl() ? $placement?->updated_at : null);
                            if ($publishedAt) {
                                $publishedDateLabel = $publishedAt->timezone(config('app.timezone'))->format('M j, Y');
                            }
                        } catch (\Throwable) {
                            $publishedDateLabel = null;
                        }
                        // Align Status column with filter chips: Approved · Needs corrections · Completed/LIVE
                        $statusDisplay = $libraryStatusDisplay($availability, (string) $submission->moderation_status);
                        $label = $statusDisplay['label'];
                        $statusCategory = $statusDisplay['category'];
                    @endphp
                    <tr id="library-row-{{ $submission->id }}" @class(['library-row--completed' => $availability === 'published'])>
                        <td>
                            @php
                                $featureThumb = $submission->feature_image_url
                                    ? safe_external_url(\App\Services\ContentUpload\ArticlePreviewHtml::normalizeSrc((string) $submission->feature_image_url), '')
                                    : '';
                            @endphp
                            @if($featureThumb !== '')
                                <img src="{{ $featureThumb }}"
                                     alt=""
                                     class="library-feature-thumb"
                                     loading="lazy"
                                     onerror="this.style.display='none'; this.insertAdjacentHTML('afterend','<span class=\'text-muted small\'>Image unavailable</span>');">
                            @endif
                            @php
                                $filenameAsTitle = $submission->usesFilenameAsTitle();
                                $titleDisplay = $filenameAsTitle
                                    ? 'Untitled'
                                    : (string) ($submission->title ?: $submission->original_filename);
                                $titleTooltip = $filenameAsTitle
                                    ? (string) ($submission->original_filename ?: 'Untitled')
                                    : $titleDisplay;
                            @endphp
                            <div class="library-title text-truncate" data-title-display="{{ $submission->id }}" title="{{ $titleTooltip }}">
                                {{ $titleDisplay }}
                            </div>
                            @if($filenameAsTitle && filled($submission->original_filename))
                                <div class="library-filename-hint text-truncate" title="{{ $submission->original_filename }}">{{ $submission->original_filename }}</div>
                            @endif
                            @if($filenameAsTitle && $submission->canEditArticle())
                                <button type="button" class="btn btn-link btn-sm p-0 library-rename-link" onclick="toggleLibraryTitleEdit({{ $submission->id }}, true)">Rename</button>
                            @endif
                            @if($submission->showJustApprovedBadge())
                                <span class="library-just-approved">Just approved</span>
                            @endif
                            @if($availability === 'published')
                                <div class="library-live-link">
                                    <div class="library-pub-details">
                                        @if($siteName)
                                            <div><strong>Published on:</strong> {{ $siteName }}</div>
                                        @else
                                            <div><strong>Status:</strong> Placement completed</div>
                                        @endif
                                        @if($libraryOrder)
                                            <div><strong>Order:</strong> #{{ $libraryOrder->id }}</div>
                                        @endif
                                        @if($placement?->price !== null)
                                            <div><strong>Price:</strong> €{{ number_format((float) $placement->price, 2) }}</div>
                                        @endif
                                        @if($publishedDateLabel)
                                            <div><strong>Published:</strong> {{ $publishedDateLabel }}</div>
                                        @endif
                                    </div>
                                    @if($liveUrl)
                                        <div class="library-live-actions">
                                            <a class="library-live-url" href="{{ $liveUrl }}" target="_blank" rel="noopener noreferrer">
                                                {{ $liveUrl }} <i class="fa fa-external-link fa-xs" aria-hidden="true"></i>
                                            </a>
                                            <button type="button"
                                                    class="library-copy-url"
                                                    data-copy-url="{{ $liveUrl }}"
                                                    onclick="copyLibraryLiveUrl(this)"
                                                    title="Copy to clipboard"
                                                    aria-label="Copy live URL to clipboard">
                                                <i class="fa fa-copy" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    @else
                                        <div class="library-live-meta mt-1">Live URL not available</div>
                                    @endif
                                </div>
                            @elseif($availability === 'in_progress' && $libraryOrder)
                                <div class="library-live-link text-muted">
                                    Order #{{ $libraryOrder->id }}
                                    @if($siteName) · {{ $siteName }} @endif
                                </div>
                            @elseif($availability === 'needs_fix')
                                <div class="library-reject-box">
                                    <span class="library-reject-box__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
                                    <div>
                                    <strong>{{ $label }}</strong>
                                    @php
                                        $reasonGroups = $submission->evaluationReasonGroups();
                                        $hitTerms = $submission->evaluationMatchedTerms();
                                        $blockedUrls = $submission->evaluationBlockedUrls();
                                    @endphp
                                    {{ $submission->libraryFixSummary() }}
                                    @if(($reasonGroups['blocking'] ?? []) !== [])
                                        <span class="library-reason-label">Blocking</span>
                                        <ul class="library-reason-list library-reason-list--blocking">
                                            @foreach(array_slice($reasonGroups['blocking'], 0, 5) as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if(($reasonGroups['advisory'] ?? []) !== [])
                                        <span class="library-reason-label">Advisory</span>
                                        <ul class="library-reason-list library-reason-list--advisory">
                                            @foreach(array_slice($reasonGroups['advisory'], 0, 5) as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if(is_array($hitTerms) && count($hitTerms))
                                        <div class="mt-1">Remove/rewrite: {{ implode(', ', array_slice($hitTerms, 0, 8)) }}</div>
                                    @endif
                                    @if(is_array($blockedUrls) && count($blockedUrls))
                                        <div class="mt-1">Blocked links: {{ implode(', ', array_slice($blockedUrls, 0, 5)) }}</div>
                                    @endif
                                    </div>
                                </div>
                                @elseif($availability === 'expired')
                                    <div class="library-expiry-hint">
                                        Preview only — original file removed
                                    </div>
                                @elseif($availability === 'available' && $submission->expires_at)
                                @php
                                    $daysLeft = $submission->daysUntilExpiry();
                                    $near = $submission->isNearExpiry((int) ($nearExpiryDays ?? 7));
                                @endphp
                                @if($daysLeft !== null)
                                    <div @class(['library-expiry-hint', 'library-expiry-hint--urgent' => $near])>
                                        @if($daysLeft <= 0)
                                            Expires today
                                        @elseif($daysLeft === 1)
                                            Expires in 1 day
                                        @else
                                            Expires in {{ $daysLeft }} days
                                        @endif
                                    </div>
                                @endif
                            @endif
                            @if($availability !== 'published')
                            <div class="library-title-edit d-none mt-2" data-title-edit="{{ $submission->id }}">
                                <div class="input-group input-group-sm" style="max-width:320px;">
                                    <input type="text" class="form-control" maxlength="200"
                                           value="{{ $submission->title }}"
                                           data-title-input="{{ $submission->id }}">
                                    <button type="button" class="btn btn-primary" onclick="saveLibraryTitle({{ $submission->id }})">Save</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="toggleLibraryTitleEdit({{ $submission->id }}, false)">Cancel</button>
                                </div>
                            </div>
                            @endif
                        </td>
                        <td>
                            @php
                                $market = $libraryMarketLabel($submission->country, $submission->language);
                            @endphp
                            <span class="library-market"@if(($market['label'] ?? '') !== ($market['codes'] ?? '')) title="{{ $market['codes'] }}"@endif>
                                {{ $market['label'] }}
                            </span>
                        </td>
                        <td>
                            <div class="library-status-wrap">
                                <span class="library-status library-status--{{ $statusCategory }}">
                                    {{ $label }}
                                    @if($statusCategory === 'processing')
                                        <span class="library-status-sweep" aria-hidden="true"></span>
                                    @endif
                                </span>
                                @if($statusCategory === 'completed' && $publishedDateLabel)
                                    <span class="library-status-time">Published {{ $publishedDateLabel }}</span>
                                @elseif($statusCategory === 'approved')
                                    @if($approvedAgo = $submission->justApprovedLabel())
                                        <span class="library-status-time">{{ $approvedAgo }}</span>
                                    @endif
                                @elseif($uploadedAgo = $submission->uploadedAgoLabel())
                                    <span class="library-status-time">Uploaded {{ $uploadedAgo }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="library-scores">
                            @if($submission->evaluated_at)
                                <span class="library-scores-pair">
                                    Unique {{ $submission->uniqueness_score !== null ? $submission->uniqueness_score.'%' : '—' }}
                                    ·
                                    Quality {{ $submission->quality_score !== null ? $submission->quality_score.'%' : '—' }}
                                </span>
                                @php
                                    $minU = (int) (($uploadCfg['evaluation']['min_uniqueness'] ?? 50));
                                    $minQ = (int) (($uploadCfg['evaluation']['min_quality'] ?? 50));
                                    $scoresAdvisory = ($submission->uniqueness_score !== null && $submission->uniqueness_score < $minU)
                                        || ($submission->quality_score !== null && $submission->quality_score < $minQ);
                                @endphp
                                @if($scoresAdvisory && $submission->isReadyForCheckout())
                                    <span class="library-scores-note">Advisory — still orderable</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-end library-actions">
                            @if($availability === 'published')
                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                            data-bs-toggle="dropdown" data-bs-display="static"
                                            data-bs-auto-close="true" aria-expanded="false" aria-haspopup="true">
                                        More
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end library-more-menu">
                                        @if($submission->hasPreviewHtml())
                                            <li>
                                                <button type="button" class="dropdown-item js-open-preview"
                                                        data-submission-id="{{ $submission->id }}">
                                                    Preview
                                                </button>
                                            </li>
                                        @endif
                                        @if($submission->canDownloadOriginal())
                                        <li>
                                            <a class="dropdown-item" href="{{ route('advertiser.content-submissions.download', $submission, false) }}">Download</a>
                                        </li>
                                        @endif
                                    </ul>
                                </div>
                            </div>
                            @else
                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                @if($submission->canOrderFromLibrary())
                                    <a class="btn btn-sm btn-primary"
                                       href="{{ route('advertiser.content-library.order', $submission, false) }}">
                                        Order
                                    </a>
                                @elseif($availability === 'evaluating')
                                    <span class="small text-muted">Processing</span>
                                @elseif($availability === 'needs_fix')
                                    @if($submission->canEditArticle() && ! $submission->needsCorrection())
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary js-open-editor"
                                                data-submission-id="{{ $submission->id }}">
                                            Edit article
                                        </button>
                                    @else
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="{{ route('advertiser.content-library', ['edit' => $submission->id, 'upload' => 1], false) }}">
                                            Resubmit
                                        </a>
                                    @endif
                                @endif
                                @if($availability === 'in_progress'
                                    || ($submission->libraryOrder() && ! $submission->isPublished() && $availability !== 'available'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('advertiser.orders', absolute: false) }}">View order</a>
                                @endif

                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                            data-bs-toggle="dropdown" data-bs-display="static"
                                            data-bs-auto-close="true" aria-expanded="false" aria-haspopup="true">
                                        More
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end library-more-menu">
                                        @if($submission->hasPreviewHtml())
                                            <li>
                                                <button type="button" class="dropdown-item js-open-preview"
                                                        data-submission-id="{{ $submission->id }}">
                                                    Preview
                                                </button>
                                            </li>
                                        @endif
                                        @if($submission->canEditArticle())
                                            <li>
                                                <button type="button" class="dropdown-item js-open-editor"
                                                        data-submission-id="{{ $submission->id }}">
                                                    Edit article
                                                </button>
                                            </li>
                                        @endif
                                        @if($submission->canDownloadOriginal())
                                        <li>
                                            <a class="dropdown-item" href="{{ route('advertiser.content-submissions.download', $submission, false) }}">Download</a>
                                        </li>
                                        @endif
                                        @if($submission->canEditArticle())
                                            <li>
                                                <button type="button" class="dropdown-item" onclick="toggleLibraryTitleEdit({{ $submission->id }}, true)">Rename</button>
                                            </li>
                                        @endif
                                        @if($submission->isArchived())
                                            <li>
                                                <button type="button" class="dropdown-item" onclick="restoreLibraryArticle({{ $submission->id }})">Restore</button>
                                            </li>
                                        @elseif($availability !== 'in_progress')
                                            <li>
                                                <button type="button" class="dropdown-item" onclick="archiveLibraryArticle({{ $submission->id }})">Archive</button>
                                            </li>
                                        @endif
                                        @if(!$submission->isLinkedToOpenOrderItem())
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button type="button" class="dropdown-item text-danger"
                                                        onclick="deleteLibraryArticle({{ $submission->id }}, @js($submission->title ?: $submission->original_filename))">
                                                    Delete
                                                </button>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            @php
                                $libraryTotalArticles = (int) ($moderationCounts['all'] ?? 0);
                                $hasActiveSearchOrFacet = ! empty($searchQuery)
                                    || (($countryFilter ?? 'all') !== 'all')
                                    || (($languageFilter ?? 'all') !== 'all');
                            @endphp
                            @if($libraryTotalArticles < 1 && ! $hasActiveSearchOrFacet && ($availabilityFilter ?? 'available') === 'available')
                                <x-ui.empty-state
                                    icon="fa-file-word"
                                    title="No articles yet"
                                    message="Upload a .docx to get your first approved article. Or browse publishers now and upload when you pick a site."
                                >
                                    @include('advertiser.partials.library-empty-actions', ['uploadsEnabled' => $uploadsEnabled])
                                </x-ui.empty-state>
                            @elseif(($availabilityFilter ?? 'all') === 'archived')
                                <x-ui.empty-state
                                    icon="fa-box-archive"
                                    title="No archived articles"
                                    message="Archive unused approved articles from the More menu. Restore anytime to order again."
                                />
                            @elseif(($availabilityFilter ?? 'all') === 'expired')
                                <x-ui.empty-state
                                    icon="fa-hourglass-end"
                                    title="No expired articles"
                                    message="Unused articles past their retention date appear here as preview only — the original file is removed. Articles linked to orders keep the original file."
                                />
                            @elseif(($availabilityFilter ?? 'all') === 'completed')
                                <x-ui.empty-state
                                    icon="fa-check-circle"
                                    title="No completed articles yet"
                                    message="They’ll appear here with their live URL once a placement is published."
                                />
                            @elseif(($availabilityFilter ?? 'all') === 'in_progress')
                                <x-ui.empty-state
                                    icon="fa-clock"
                                    title="No articles processing"
                                    message="After you Order an approved article, it stays here until the publisher posts the live URL."
                                />
                            @elseif(($availabilityFilter ?? 'all') === 'needs_fix'
                                || ($statusFilter ?? 'all') === 'rejected')
                                <x-ui.empty-state
                                    icon="fa-pen-to-square"
                                    title="No articles need corrections"
                                    message="Rejected or scan-error articles will show here so you can revise and resubmit."
                                />
                            @elseif(($availabilityFilter ?? 'all') === 'available' || ($statusFilter ?? 'all') === 'approved')
                                <x-ui.empty-state
                                    icon="fa-circle-check"
                                    title="No approved articles ready to order"
                                    message="Approved articles available for publication will show here."
                                />
                            @elseif($hasActiveSearchOrFacet || ($availabilityFilter ?? 'all') !== 'all')
                                No articles match these filters.
                            @else
                                <x-ui.empty-state
                                    icon="fa-file-word"
                                    title="No articles yet"
                                    message="Upload a .docx to get your first approved article. Or browse publishers now and upload when you pick a site."
                                >
                                    @include('advertiser.partials.library-empty-actions', ['uploadsEnabled' => $uploadsEnabled])
                                </x-ui.empty-state>
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('partials.slb-pagination', [
        'paginator' => $submissions,
        'noun' => 'article',
        'ariaLabel' => 'Library pages',
    ])
