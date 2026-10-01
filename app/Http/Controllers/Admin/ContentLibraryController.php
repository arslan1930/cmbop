<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ContentModerationLog;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Advertiser\ContentLibrarySearchQuery;
use App\Services\ContentUpload\AdminLibraryStaffActions;
use App\Services\ContentUpload\ArticleHtmlSanitizer;
use App\Services\ContentUpload\ArticlePreviewHtml;
use App\Support\AdminContentLibrary;
use App\Support\ArticleDownload;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ContentLibraryController extends Controller
{
    /** @var array<string, bool> */
    private array $schemaColumnCache = [];

    public function __construct(
        private ContentLibrarySearchQuery $librarySearch,
        private ArticleHtmlSanitizer $sanitizer,
        private AdminLibraryStaffActions $staffActions,
    ) {}

    public function index(Request $request)
    {
        return view('admin.content-library.index', $this->libraryIndexData($request));
    }

    public function results(Request $request)
    {
        if (! config('content_library.live_search.enabled', true)) {
            abort(404);
        }

        return response()
            ->view('admin.content-library.results', $this->libraryIndexData($request))
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<string, mixed>
     */
    private function libraryIndexData(Request $request): array
    {
        $filters = $this->parseFilters($request);
        AdminContentLibrary::rememberReturnQuery($request);
        $page = (int) scalar_text($request->query('page', 1));
        if ($page < 1) {
            $page = 1;
        }

        $submissions = $this->emptyLibraryPaginator($page);
        if ($this->schemaTableAvailable('content_submissions')) {
            try {
                $this->refreshStaleFileOnDiskFlags();
                $query = ContentSubmission::query()
                    ->forLibraryList()
                    ->with($this->libraryListRelations());

                $this->applyListFilters($query, $filters);
                $this->applySort($query, $filters['sort']);
                $submissions = $query->paginate(30, ['*'], 'page', $page)->withQueryString();
                $submissions->setPath($this->libraryListPath());
            } catch (\Throwable $e) {
                report($e);
                session()->flash(
                    'error',
                    UserFacingError::message($e, 'We could not load the content library. Please refresh and try again.')
                );
                $submissions = $this->emptyLibraryPaginator($page);
            }
        }

        $this->attachFileOnDiskFlags($submissions);
        foreach ($submissions as $submission) {
            if ($submission instanceof ContentSubmission) {
                $this->sealMissingLibraryRelations($submission);
            }
        }

        $filterUser = $filters['user_id'] > 0
            ? $this->safeAdvertiserLookup($filters['user_id'])
            : null;
        $advertiserUnmatched = $filters['advertiser'] !== '' && $filters['user_id'] < 0;
        $filterQuery = $this->filterQuery($filters);

        return [
            'submissions' => $submissions,
            'availability' => $filters['availability'],
            'language' => $filters['language'] ?: 'all',
            'country' => $filters['country'] ?: 'all',
            'search' => $filters['search'],
            'advertiserQuery' => $filters['advertiser'],
            'sort' => $filters['sort'],
            'attachment' => $filters['attachment'],
            'file' => $filters['file'],
            'expiring' => $filters['expiring'],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'userId' => $filters['user_id'] > 0 ? $filters['user_id'] : null,
            'filterUser' => $filterUser,
            'advertiserUnmatched' => $advertiserUnmatched,
            'availabilityCounts' => $this->availabilityCounts($filters),
            'countries' => $this->marketCodes('country'),
            'languages' => $this->marketCodes('language'),
            'filterQuery' => $filterQuery,
            'listUrl' => AdminContentLibrary::listUrl($filterQuery),
            'liveSearchEnabled' => (bool) config('content_library.live_search.enabled', true),
            'bulkLimit' => $this->bulkLimit(),
            'exportLimit' => AdminContentLibrary::EXPORT_LIMIT,
        ];
    }

    public function show(Request $request, ContentSubmission $submission)
    {
        $filters = $this->parseFilters($request);
        $placement = null;
        $previewHtml = '';
        $reasons = [];
        $matchedTerms = [];
        $blockedUrls = [];
        $availability = 'evaluating';
        $fileOnDisk = false;
        $liveUrl = null;
        $notice = '';
        $libraryOrder = null;
        $canRetry = false;
        $canOverrideApprove = ! $submission->isArchived();
        $canOverrideReject = ! $submission->isArchived();
        $canArchive = ! $submission->isArchived();
        $canRestore = $submission->isArchived();

        try {
            $submission->load($this->libraryShowRelations());
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Some article details could not be loaded. Preview or order links may be incomplete.')
            );
        }

        $this->sealMissingLibraryRelations($submission);

        try {
            $placement = $submission->libraryPlacementItem();
            $fileOnDisk = $this->staffActions->fileOnDisk($submission);
            $this->persistFileOnDiskFlag($submission, $fileOnDisk);
            $previewHtml = $this->staffPreviewHtml($submission);
            $reasons = $submission->evaluationReasonGroups();
            $matchedTerms = $submission->evaluationMatchedTerms();
            $blockedUrls = $submission->evaluationBlockedUrls();
            $availability = $submission->libraryAvailability();
            $liveUrl = $submission->liveUrl();
            $notice = $submission->editorNotice();
            $libraryOrder = $submission->libraryOrder();
            $canRetry = $this->canRetry($submission, $fileOnDisk);
            $canOverrideApprove = ! $submission->isArchived();
            $canOverrideReject = ! $submission->isArchived() && ! $submission->isLockedByPaidOrder();
            $canArchive = ! $submission->isArchived()
                && ! (($submission->isInUse() || $submission->isClaimedByAnotherOrder()) && ! $submission->isPublished());
            $canRestore = $submission->isArchived();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Some article details could not be loaded. Preview or order links may be incomplete.')
            );
        }

        return view('admin.content-library.show', [
            'submission' => $submission,
            'previewHtml' => $previewHtml,
            'reasons' => $reasons,
            'matchedTerms' => $matchedTerms,
            'blockedUrls' => $blockedUrls,
            'availability' => $availability,
            'fileOnDisk' => $fileOnDisk,
            'placement' => $placement,
            'liveUrl' => $liveUrl,
            'notice' => $notice,
            'libraryOrder' => $libraryOrder,
            'filterQuery' => $this->filterQuery($filters),
            'listUrl' => $this->libraryListUrl($request),
            'scanHistory' => $this->scanHistory($submission),
            'staffActivity' => $this->staffActivity($submission),
            'canRetry' => $canRetry,
            'canOverrideApprove' => $canOverrideApprove,
            'canOverrideReject' => $canOverrideReject,
            'canArchive' => $canArchive,
            'canRestore' => $canRestore,
        ]);
    }

    public function download(ContentSubmission $submission): StreamedResponse|RedirectResponse
    {
        try {
            $disk = Storage::disk($submission->disk ?: 'local');
            $path = (string) $submission->path;
            if ($path === '' || str_contains($path, '..') || ! $disk->exists($path)) {
                abort(404, 'File not found');
            }

            $filename = str_replace(["\r", "\n", '"'], '', basename((string) ($submission->original_filename ?: 'article.docx')));

            return $disk->download(
                $path,
                $filename !== '' ? $filename : 'article.docx',
                ArticleDownload::headers(
                    $filename !== '' ? $filename : 'article.docx',
                    (string) ($submission->mime ?: 'application/octet-stream')
                )
            );
        } catch (HttpException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            abort(404, 'File not found');
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                UserFacingError::message($e, 'We could not download that file. Please try again.')
            );
        }
    }

    public function retry(ContentSubmission $submission): RedirectResponse
    {
        try {
            $result = $this->staffActions->retry($submission);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: 'Could not re-evaluate this article.');
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not re-evaluate this article.'));
        }

        $fresh = $submission->fresh() ?? $submission;
        $status = (string) ($result['moderation_status'] ?? $fresh->moderation_status);
        $message = trim((string) ($result['message'] ?? ''));

        ActivityLogger::tryLog(
            'content.re_evaluated',
            (auth()->user()?->name ?? 'Admin').' re-evaluated library article #'.$fresh->id,
            $fresh,
            [
                'submission_id' => $fresh->id,
                'user_id' => $fresh->user_id,
                'moderation_status' => $status,
                'approved' => (bool) ($result['approved'] ?? false),
            ],
            'Article #'.$fresh->id
        );

        return back()->with(
            'success',
            'Re-evaluation finished ('.$status.').'.($message !== '' ? ' '.$message : '')
        );
    }

    public function override(Request $request, ContentSubmission $submission): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'notes' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $admin = $request->user();
        abort_unless($admin instanceof User, 403);

        try {
            $result = $this->staffActions->override($submission, $data['decision'], $admin, $data['notes']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: 'Override failed.');
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Override failed.'));
        }

        $fresh = $result['submission'] ?? $submission->fresh();
        $flash = ! empty($result['already'])
            ? (string) ($result['message'] ?: $this->overrideFlash($fresh, $data['decision']))
            : $this->overrideFlash($fresh, $data['decision']);

        if ($fresh && empty($result['already'])) {
            $this->logLibraryStaff(
                'content.overridden',
                $data['decision'] === 'approved' ? 'approved' : 'rejected',
                $fresh,
                [
                    'decision' => $data['decision'],
                    'moderation_status' => $fresh->moderation_status,
                ]
            );
        }

        return back()->with('success', $flash);
    }

    public function archive(ContentSubmission $submission): RedirectResponse
    {
        $wasArchived = $submission->isArchived();

        try {
            $this->staffActions->archive($submission);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: 'Could not archive this article.');
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not archive this article.'));
        }

        $fresh = $submission->fresh() ?? $submission;
        if (! $wasArchived && $fresh->isArchived()) {
            ActivityLogger::tryLog(
                'content.archived',
                (auth()->user()?->name ?? 'Admin').' archived library article #'.$fresh->id,
                $fresh,
                [
                    'submission_id' => $fresh->id,
                    'user_id' => $fresh->user_id,
                ],
                'Article #'.$fresh->id
            );
        }

        return back()->with('success', 'Article #'.$submission->id.' archived.');
    }

    public function restore(ContentSubmission $submission): RedirectResponse
    {
        $wasArchived = $submission->isArchived();

        try {
            $this->staffActions->restore($submission);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: 'Could not restore this article.');
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not restore this article.'));
        }

        $fresh = $submission->fresh() ?? $submission;

        if ($wasArchived && ! $fresh->isArchived()) {
            ActivityLogger::tryLog(
                'content.restored',
                (auth()->user()?->name ?? 'Admin').' restored library article #'.$fresh->id,
                $fresh,
                [
                    'submission_id' => $fresh->id,
                    'user_id' => $fresh->user_id,
                ],
                'Article #'.$fresh->id
            );
        }

        return back()->with('success', 'Article #'.$submission->id.' restored from archive.');
    }

    /**
     * @return array{
     *     availability:string,
     *     language:string,
     *     country:string,
     *     search:string,
     *     advertiser:string,
     *     sort:string,
     *     attachment:string,
     *     expiring:string,
     *     from:string,
     *     to:string,
     *     user_id:int
     * }
     */
    protected function parseFilters(Request $request): array
    {
        $query = AdminContentLibrary::indexQuery($request);
        $availability = (string) ($query['availability'] ?? 'all');
        $advertiser = (string) ($query['advertiser'] ?? '');
        $userId = (int) ($query['user_id'] ?? 0);

        if ($userId <= 0 && $advertiser !== '') {
            $resolved = $this->resolveAdvertiserId($advertiser);
            $userId = $resolved > 0 ? $resolved : -1;
        }

        return [
            'availability' => $availability !== '' ? $availability : 'all',
            'language' => (string) ($query['language'] ?? ''),
            'country' => (string) ($query['country'] ?? ''),
            'search' => (string) ($query['q'] ?? ''),
            'advertiser' => $advertiser,
            'sort' => (string) ($query['sort'] ?? 'latest'),
            'attachment' => (string) ($query['attachment'] ?? ''),
            'file' => (string) ($query['file'] ?? ''),
            'expiring' => (string) ($query['expiring'] ?? ''),
            'from' => (string) ($query['from'] ?? ''),
            'to' => (string) ($query['to'] ?? ''),
            'user_id' => $userId,
        ];
    }

    /**
     * @param  Builder<ContentSubmission>  $query
     * @param  array{availability:string, language:string, country:string, search:string, advertiser:string, sort:string, user_id:int}  $filters
     */
    protected function applyListFilters(Builder $query, array $filters): void
    {
        $this->applyAvailability($query, $filters['availability']);

        if ($filters['language'] !== '') {
            $query->where('language', $filters['language']);
        }
        if ($filters['country'] !== '') {
            $query->where('country', $filters['country']);
        }
        if ($filters['user_id'] > 0) {
            $query->where('user_id', $filters['user_id']);
        } elseif ($filters['user_id'] < 0) {
            $query->whereRaw('0 = 1');
        }
        if ($filters['search'] !== '') {
            $this->applySearch($query, $filters['search']);
        }
        if (($filters['attachment'] ?? '') === 'order' && $this->schemaTableAvailable('orders')) {
            $query->where(function (Builder $outer) {
                $outer->withOpenOwnerOrder()
                    ->orWhere(function (Builder $claimed) {
                        $claimed->withActiveOrderClaim();
                    });
            });
        } elseif (($filters['attachment'] ?? '') === 'none' && $this->schemaTableAvailable('orders')) {
            $query->withoutOpenOwnerOrder()->withoutActiveOrderClaim()->withoutOpenOrderItemLink();
        }
        if (($filters['file'] ?? '') === 'missing' && $this->schemaColumnAvailable('content_submissions', 'file_on_disk')) {
            $query->whereNotNull('path')->where('path', '!=', '')->where('file_on_disk', false);
        }
        if (($filters['expiring'] ?? '') === 'soon') {
            $query->nearExpiryInLibrary(AdminContentLibrary::EXPIRING_DAYS);
        }
        if (($filters['from'] ?? '') !== '') {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (($filters['to'] ?? '') !== '') {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
    }

    /**
     * @param  Builder<ContentSubmission>  $query
     */
    protected function applySort(Builder $query, string $sort): void
    {
        if ($sort === 'title') {
            $query->orderBy('title')->orderByDesc('id');

            return;
        }

        if ($sort === 'expires') {
            $query->orderByRaw('case when expires_at is null then 1 else 0 end')
                ->orderBy('expires_at')
                ->orderByDesc('id');

            return;
        }

        if ($sort === 'uniqueness') {
            $query->orderByRaw('case when uniqueness_score is null then 1 else 0 end')
                ->orderByDesc('uniqueness_score')
                ->orderByDesc('id');

            return;
        }

        if ($sort === 'quality') {
            $query->orderByRaw('case when quality_score is null then 1 else 0 end')
                ->orderByDesc('quality_score')
                ->orderByDesc('id');

            return;
        }

        $query->latest('id');
    }

    /**
     * @param  Builder<ContentSubmission>  $query
     */
    protected function applyAvailability(Builder $query, string $availability): void
    {
        if ($availability === 'archived') {
            $query->archived();

            return;
        }

        $query->notArchived();

        if ($availability === 'expired') {
            if ($this->schemaTableAvailable('orders')) {
                $query->expiredUnused();
            }

            return;
        }

        if ($this->schemaTableAvailable('orders')) {
            $this->excludeExpiredUnused($query);
        }

        if (! $this->schemaTableAvailable('orders')) {
            return;
        }

        if ($availability === 'available') {
            $query->checkoutReady();
        } elseif ($availability === 'evaluating') {
            $query->evaluatingInLibrary();
        } elseif ($availability === 'in_progress') {
            $query->inProgressInLibrary();
        } elseif ($availability === 'needs_fix') {
            $query->needsLibraryFix();
        } elseif ($availability === 'completed') {
            $query->withCurrentLivePlacement();
        }
    }

    /**
     * Unused expired rows belong only in the Expired chip — not All / Approved.
     *
     * @param  Builder<ContentSubmission>  $query
     */
    protected function excludeExpiredUnused(Builder $query): void
    {
        $query->where(function ($q) {
            $q->whereNotExpired()
                ->orWhere(function ($owned) {
                    $owned->withOpenOwnerOrder();
                })->orWhere(function ($claimed) {
                    $claimed->withActiveOrderClaim();
                });
        });
    }

    /**
     * @param  Builder<ContentSubmission>  $query
     */
    protected function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $outer) use ($search) {
            $this->librarySearch->apply($outer, $search);
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $outer->orWhereHas('user', function ($u) use ($like) {
                $u->where('email', 'like', $like)->orWhere('name', 'like', $like);
            });
        });
    }

    /**
     * @param  array{availability:string, language:string, country:string, search:string, advertiser:string, sort:string, user_id:int}  $filters
     * @return array<string, int>
     */
    protected function availabilityCounts(array $filters): array
    {
        $empty = [
            'all' => 0,
            'available' => 0,
            'evaluating' => 0,
            'in_progress' => 0,
            'needs_fix' => 0,
            'completed' => 0,
            'expired' => 0,
            'archived' => 0,
        ];

        if (! $this->schemaTableAvailable('content_submissions')) {
            return $empty;
        }

        try {
            $base = ContentSubmission::query();
            if ($filters['language'] !== '') {
                $base->where('language', $filters['language']);
            }
            if ($filters['country'] !== '') {
                $base->where('country', $filters['country']);
            }
            if ($filters['user_id'] > 0) {
                $base->where('user_id', $filters['user_id']);
            } elseif ($filters['user_id'] < 0) {
                $base->whereRaw('0 = 1');
            }
            if ($filters['search'] !== '') {
                $this->applySearch($base, $filters['search']);
            }
            if (($filters['attachment'] ?? '') === 'order' && $this->schemaTableAvailable('orders')) {
                $base->where(function (Builder $outer) {
                    $outer->withOpenOwnerOrder()
                        ->orWhere(function (Builder $claimed) {
                            $claimed->withActiveOrderClaim();
                        });
                });
            } elseif (($filters['attachment'] ?? '') === 'none' && $this->schemaTableAvailable('orders')) {
                $base->withoutOpenOwnerOrder()->withoutActiveOrderClaim()->withoutOpenOrderItemLink();
            }
            if (($filters['file'] ?? '') === 'missing' && $this->schemaColumnAvailable('content_submissions', 'file_on_disk')) {
                $base->whereNotNull('path')->where('path', '!=', '')->where('file_on_disk', false);
            }
            if (($filters['expiring'] ?? '') === 'soon') {
                $base->nearExpiryInLibrary(AdminContentLibrary::EXPIRING_DAYS);
            }
            if (($filters['from'] ?? '') !== '') {
                $base->whereDate('created_at', '>=', $filters['from']);
            }
            if (($filters['to'] ?? '') !== '') {
                $base->whereDate('created_at', '<=', $filters['to']);
            }

            $active = (clone $base)->notArchived();
            if ($this->schemaTableAvailable('orders')) {
                $this->excludeExpiredUnused($active);
            }

            $counts = [
                'all' => (int) (clone $active)->count(),
                'available' => 0,
                'evaluating' => 0,
                'in_progress' => 0,
                'needs_fix' => 0,
                'completed' => 0,
                'expired' => 0,
                'archived' => (int) (clone $base)->archived()->count(),
            ];

            if ($this->schemaTableAvailable('orders')) {
                $counts['available'] = (int) (clone $active)->checkoutReady()->count();
                $counts['evaluating'] = (int) (clone $active)->evaluatingInLibrary()->count();
                $counts['in_progress'] = (int) (clone $active)->inProgressInLibrary()->count();
                $counts['needs_fix'] = (int) (clone $active)->needsLibraryFix()->count();
                $counts['completed'] = (int) (clone $active)->withCurrentLivePlacement()->count();
                $counts['expired'] = (int) (clone $base)->notArchived()->expiredUnused()->count();
            }

            return $counts;
        } catch (\Throwable $e) {
            Log::warning('Admin content library counts leftover query failed', [
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }
    }

    /**
     * @return list<string>
     */
    protected function marketCodes(string $column): array
    {
        if (! $this->schemaTableAvailable('content_submissions')) {
            return [];
        }

        try {
            return ContentSubmission::query()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->distinct()
                ->orderBy($column)
                ->pluck($column)
                ->map(fn ($code) => strtolower((string) $code))
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array{availability:string, language:string, country:string, search:string, advertiser:string, sort:string, user_id:int}  $filters
     * @return array<string, string|int>
     */
    protected function filterQuery(array $filters): array
    {
        $query = [];
        if ($filters['availability'] !== '' && $filters['availability'] !== 'all') {
            $query['availability'] = $filters['availability'];
        }
        if ($filters['language'] !== '') {
            $query['language'] = $filters['language'];
        }
        if ($filters['country'] !== '') {
            $query['country'] = $filters['country'];
        }
        if ($filters['search'] !== '') {
            $query['q'] = $filters['search'];
        }
        if ($filters['advertiser'] !== '') {
            $query['advertiser'] = $filters['advertiser'];
        }
        if ($filters['sort'] !== '' && $filters['sort'] !== 'latest') {
            $query['sort'] = $filters['sort'];
        }
        if ($filters['user_id'] > 0) {
            $query['user_id'] = $filters['user_id'];
        }
        if (($filters['attachment'] ?? '') !== '') {
            $query['attachment'] = $filters['attachment'];
        }
        if (($filters['file'] ?? '') === 'missing') {
            $query['file'] = 'missing';
        }
        if (($filters['expiring'] ?? '') === 'soon') {
            $query['expiring'] = 'soon';
        }
        if (($filters['from'] ?? '') !== '') {
            $query['from'] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $query['to'] = $filters['to'];
        }

        return $query;
    }

    protected function overrideFlash(?ContentSubmission $submission, string $decision): string
    {
        if (! $submission) {
            return $decision === 'approved' ? 'Article approved.' : 'Article rejected.';
        }

        if ($decision !== 'approved') {
            return 'Article #'.$submission->id.' rejected.';
        }

        if ($submission->isReadyForCheckout()) {
            return 'Article #'.$submission->id.' approved. The advertiser can attach it in the catalog.';
        }

        if ($submission->isUsableAfterStaffApproval()) {
            return 'Article #'.$submission->id.' approved. It stays on the open order and can be fulfilled.';
        }

        $notice = trim($submission->editorNotice());

        return 'Article #'.$submission->id.' approved, but it is still not checkout-ready'
            .($notice !== '' ? ': '.$notice : '.');
    }

    protected function staffPreviewHtml(ContentSubmission $submission): string
    {
        $html = $this->sanitizer->sanitize((string) ($submission->preview_html ?? ''));
        $html = ArticlePreviewHtml::normalize($html);
        $html = ArticlePreviewHtml::highlightTerms($html, $submission->evaluationMatchedTerms());

        return ArticlePreviewHtml::highlightBlockedLinks($html, $submission->evaluationBlockedUrls());
    }

    protected function canRetry(ContentSubmission $submission, bool $fileOnDisk): bool
    {
        if ($submission->isArchived() || $submission->isLockedByPaidOrder()) {
            return false;
        }

        if ($submission->isUnusedExpired()) {
            return false;
        }

        return $fileOnDisk || $submission->hasPreviewHtml();
    }

    /**
     * @return list<string>
     */
    private function libraryListRelations(): array
    {
        $with = ['user:id,name,email'];
        if ($this->schemaTableAvailable('orders')) {
            $with[] = 'order:id,status,payment_status,order_number';
        }
        if ($this->schemaTableAvailable('order_items') && $this->schemaTableAvailable('sites')) {
            $with[] = 'orderItem.site:id,site_name,site_url';
            $with[] = 'orderItems.site:id,site_name,site_url';
        }
        if ($this->schemaTableAvailable('order_items') && $this->schemaTableAvailable('orders')) {
            $with[] = 'orderItems.order:id,status,payment_status,order_number';
        }

        return $with;
    }

    /**
     * @return list<string>
     */
    private function libraryShowRelations(): array
    {
        $with = ['user:id,name,email'];
        if ($this->schemaTableAvailable('orders')) {
            $with[] = 'order:id,status,payment_status,order_number,user_id';
        }
        if ($this->schemaTableAvailable('order_items') && $this->schemaTableAvailable('sites')) {
            $with[] = 'orderItem.site:id,site_name,site_url';
            $with[] = 'orderItems.site:id,site_name,site_url';
        }
        if ($this->schemaTableAvailable('order_items') && $this->schemaTableAvailable('orders')) {
            $with[] = 'orderItems.order:id,status,payment_status,order_number';
        }
        if ($this->schemaTableAvailable('content_moderation_logs')) {
            $with[] = 'moderationLog.overrider:id,name,email';
        }

        return $with;
    }

    private function emptyLibraryPaginator(int $page): LengthAwarePaginator
    {
        $paginator = new LengthAwarePaginator([], 0, 30, $page);
        $paginator->setPath($this->libraryListPath());

        return $paginator->withQueryString();
    }

    private function libraryListPath(): string
    {
        return route('admin.content-library.index', absolute: false);
    }

    private function schemaTableAvailable(string $table): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        try {
            DB::table($table)->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function schemaColumnAvailable(string $table, string $column): bool
    {
        $key = $table.'.'.$column;
        if (array_key_exists($key, $this->schemaColumnCache)) {
            return $this->schemaColumnCache[$key];
        }

        if (! $this->schemaTableAvailable($table)) {
            return $this->schemaColumnCache[$key] = false;
        }

        try {
            return $this->schemaColumnCache[$key] = Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return $this->schemaColumnCache[$key] = false;
        }
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        $filters = $this->parseFilters($request);

        try {
            $this->refreshStaleFileOnDiskFlags();
            $query = ContentSubmission::query()->forLibraryList()->with(['user:id,name,email']);
            if ($this->schemaTableAvailable('orders')) {
                $query->with(['order:id,order_number']);
            }
            $this->applyListFilters($query, $filters);
            $this->applySort($query, $filters['sort']);
            $total = (clone $query)->count();
            $rows = $query->limit(AdminContentLibrary::EXPORT_LIMIT)->get();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->to($this->libraryListUrl($request))
                ->with('error', UserFacingError::message($e, 'We could not export the content library. Please try again.'));
        }

        $truncated = $total > AdminContentLibrary::EXPORT_LIMIT;
        $filename = 'content-library-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $truncated) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id',
                'title',
                'advertiser_email',
                'market',
                'availability',
                'moderation_status',
                'uniqueness',
                'quality',
                'order_number',
                'file_on_disk',
                'expires_at',
            ]);
            if ($truncated) {
                fputcsv($out, ['# truncated at '.AdminContentLibrary::EXPORT_LIMIT]);
            }

            foreach ($rows as $submission) {
                $market = trim(implode('/', array_filter([
                    strtoupper((string) $submission->country),
                    strtoupper((string) $submission->language),
                ])));
                try {
                    $availability = $submission->libraryAvailability();
                } catch (\Throwable) {
                    $availability = 'unavailable';
                }
                $order = null;
                try {
                    $order = $submission->libraryOrder();
                } catch (\Throwable) {
                }
                fputcsv($out, [
                    $submission->id,
                    $submission->title ?: $submission->original_filename,
                    $submission->user?->email,
                    $market,
                    $availability,
                    $submission->moderation_status,
                    $submission->uniqueness_score,
                    $submission->quality_score,
                    $order?->order_number,
                    $this->staffActions->fileOnDisk($submission) ? 'yes' : 'no',
                    optional($submission->expires_at)?->toDateString(),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function bulkRetry(Request $request): RedirectResponse
    {
        return $this->runBulk($request, 'retry', 'Re-evaluated %d article(s).');
    }

    public function bulkArchive(Request $request): RedirectResponse
    {
        return $this->runBulk($request, 'archive', 'Archived %d article(s).');
    }

    public function bulkRestore(Request $request): RedirectResponse
    {
        return $this->runBulk($request, 'restore', 'Restored %d article(s).');
    }

    private function runBulk(Request $request, string $action, string $success): RedirectResponse
    {
        $ids = $this->bulkIds($request);
        if ($ids === []) {
            return back()->with('error', 'Select at least one article.');
        }

        try {
            $rows = ContentSubmission::query()->whereIn('id', $ids)->get();
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                UserFacingError::message($e, 'We could not update those articles. Please try again.')
            );
        }

        $done = 0;
        $failedIds = [];
        foreach ($rows as $submission) {
            try {
                if ($action === 'retry') {
                    $this->staffActions->retry($submission);
                } elseif ($action === 'restore') {
                    $this->staffActions->restore($submission);
                } else {
                    $this->staffActions->archive($submission);
                }
                $fresh = $submission->fresh() ?? $submission;
                if ($action === 'retry') {
                    $this->logLibraryStaff('content.re_evaluated', 're-evaluated', $fresh);
                } elseif ($action === 'restore') {
                    $this->logLibraryStaff('content.restored', 'restored', $fresh);
                } else {
                    $this->logLibraryStaff('content.archived', 'archived', $fresh);
                }
                $done++;
            } catch (ValidationException) {
                $failedIds[] = (int) $submission->id;
            } catch (\Throwable $e) {
                report($e);
                $failedIds[] = (int) $submission->id;
            }
        }

        $flash = sprintf($success, $done);
        if ($failedIds !== []) {
            $flash .= ' Could not update #'.implode(', #', $failedIds).'.';
        }

        return back()->with($done > 0 ? 'success' : 'error', $flash);
    }

    /**
     * @return list<int>
     */
    private function bulkIds(Request $request): array
    {
        $raw = $request->input('ids', []);
        if (! is_array($raw)) {
            $raw = $raw === null || $raw === '' ? [] : [$raw];
        }

        return collect($raw)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->take($this->bulkLimit())
            ->values()
            ->all();
    }

    private function bulkLimit(): int
    {
        $limit = (int) config('content_library.bulk_limit', 50);

        return $limit > 0 ? $limit : 50;
    }

    private function resolveAdvertiserId(string $needle): int
    {
        try {
            $like = '%'.addcslashes($needle, '%_\\').'%';

            $user = User::query()
                ->where(function ($q) use ($needle, $like) {
                    $q->where('email', $needle)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('name', 'like', $like);
                })
                ->orderByRaw('case when email = ? then 0 else 1 end', [$needle])
                ->first(['id']);

            return (int) ($user?->id ?? 0);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function safeAdvertiserLookup(int $id): ?User
    {
        try {
            return User::query()->select(['id', 'name', 'email'])->find($id);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function sealMissingLibraryRelations(ContentSubmission $submission): void
    {
        if (! $this->schemaTableAvailable('order_items')) {
            if (! $submission->relationLoaded('orderItem')) {
                $submission->setRelation('orderItem', null);
            }
            if (! $submission->relationLoaded('orderItems')) {
                $submission->setRelation('orderItems', $submission->newCollection());
            }
        }

        if (! $this->schemaTableAvailable('orders') && ! $submission->relationLoaded('order')) {
            $submission->setRelation('order', null);
        }

        if (! $this->schemaTableAvailable('content_moderation_logs') && ! $submission->relationLoaded('moderationLog')) {
            $submission->setRelation('moderationLog', null);
        }
    }

    private function libraryListUrl(Request $request): string
    {
        $fromRequest = AdminContentLibrary::indexQuery($request);
        if ($fromRequest !== []) {
            return AdminContentLibrary::listUrl($fromRequest);
        }

        return AdminContentLibrary::listUrl(AdminContentLibrary::sessionReturnQuery($request));
    }

    /**
     * @return list<ContentModerationLog>
     */
    private function scanHistory(ContentSubmission $submission): array
    {
        if (! $this->schemaTableAvailable('content_moderation_logs')) {
            return [];
        }

        try {
            return ContentModerationLog::query()
                ->where('content_submission_id', $submission->id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<ActivityLog>
     */
    private function staffActivity(ContentSubmission $submission): array
    {
        if (! $this->schemaTableAvailable('activity_logs')) {
            return [];
        }

        try {
            return ActivityLog::query()
                ->where('subject_type', ContentSubmission::class)
                ->where('subject_id', $submission->id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function attachFileOnDiskFlags(LengthAwarePaginator $submissions): void
    {
        foreach ($submissions as $submission) {
            if (! $submission instanceof ContentSubmission) {
                continue;
            }
            $this->persistFileOnDiskFlag($submission, $this->staffActions->fileOnDisk($submission));
        }
    }

    private function refreshStaleFileOnDiskFlags(int $limit = 200): void
    {
        if (! $this->schemaColumnAvailable('content_submissions', 'file_on_disk')) {
            return;
        }

        try {
            $rows = ContentSubmission::query()
                ->whereNotNull('path')
                ->where('path', '!=', '')
                ->whereNull('file_on_disk')
                ->orderBy('id')
                ->limit(max(1, $limit))
                ->get(['id', 'path', 'disk']);
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $this->persistFileOnDiskFlag($row, $this->staffActions->fileOnDisk($row));
        }
    }

    private function persistFileOnDiskFlag(ContentSubmission $submission, bool $onDisk): void
    {
        $submission->setAttribute('file_on_disk', $onDisk);
        if (! $submission->hasStoredFile() || ! $this->schemaColumnAvailable('content_submissions', 'file_on_disk')) {
            return;
        }

        $want = $onDisk ? 1 : 0;
        $stored = $submission->getRawOriginal('file_on_disk');
        if ($stored !== null && (int) $stored === $want) {
            return;
        }

        try {
            ContentSubmission::query()->whereKey($submission->id)->update(['file_on_disk' => $want]);
        } catch (\Throwable) {
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logLibraryStaff(string $action, string $verb, ContentSubmission $submission, array $extra = []): void
    {
        ActivityLogger::tryLog(
            $action,
            (auth()->user()?->name ?? 'Admin').' '.$verb.' library article #'.$submission->id,
            $submission,
            array_merge([
                'submission_id' => $submission->id,
                'user_id' => $submission->user_id,
            ], $extra),
            'Article #'.$submission->id
        );
    }
}
