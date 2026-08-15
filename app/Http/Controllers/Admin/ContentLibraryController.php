<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\Advertiser\ContentLibrarySearchQuery;
use App\Services\ContentUpload\AdminLibraryStaffActions;
use App\Services\ContentUpload\ArticleHtmlSanitizer;
use App\Services\ContentUpload\ArticlePreviewHtml;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentLibraryController extends Controller
{
    public function __construct(
        private ContentLibrarySearchQuery $librarySearch,
        private ArticleHtmlSanitizer $sanitizer,
        private AdminLibraryStaffActions $staffActions,
    ) {}

    public function index(Request $request)
    {
        $filters = $this->parseFilters($request);

        $query = ContentSubmission::query()
            ->forLibraryList()
            ->with([
                'user:id,name,email',
                'order:id,status,payment_status,order_number',
                'orderItem.site:id,site_name,site_url',
                'orderItems.order:id,status,payment_status',
            ])
            ->latest('id');

        $this->applyListFilters($query, $filters);

        $page = (int) scalar_text($request->query('page', 1));
        if ($page < 1) {
            $page = 1;
        }
        $submissions = $query->paginate(30, ['*'], 'page', $page)->withQueryString();

        $filterUser = $filters['user_id'] > 0
            ? User::query()->select(['id', 'name', 'email'])->find($filters['user_id'])
            : null;

        return view('admin.content-library.index', [
            'submissions' => $submissions,
            'availability' => $filters['availability'],
            'language' => $filters['language'] ?: 'all',
            'country' => $filters['country'] ?: 'all',
            'search' => $filters['search'],
            'userId' => $filters['user_id'] ?: null,
            'filterUser' => $filterUser,
            'availabilityCounts' => $this->availabilityCounts($filters),
            'countries' => $this->marketCodes('country'),
            'languages' => $this->marketCodes('language'),
            'filterQuery' => $this->filterQuery($filters),
        ]);
    }

    public function show(Request $request, ContentSubmission $submission)
    {
        $submission->load([
            'user:id,name,email',
            'order:id,status,payment_status,order_number,user_id',
            'orderItem.site:id,site_name,site_url',
            'orderItems.site:id,site_name,site_url',
            'orderItems.order:id,status,payment_status,order_number',
        ]);

        $filters = $this->parseFilters($request);
        $placement = $submission->placementItem();
        $fileOnDisk = $this->staffActions->fileOnDisk($submission);

        return view('admin.content-library.show', [
            'submission' => $submission,
            'previewHtml' => $this->staffPreviewHtml($submission),
            'reasons' => $submission->evaluationReasonGroups(),
            'matchedTerms' => $submission->evaluationMatchedTerms(),
            'blockedUrls' => $submission->evaluationBlockedUrls(),
            'availability' => $submission->libraryAvailability(),
            'fileOnDisk' => $fileOnDisk,
            'placement' => $placement,
            'liveUrl' => $submission->liveUrl(),
            'notice' => $submission->editorNotice(),
            'filterQuery' => $this->filterQuery($filters),
            'canRetry' => $this->canRetry($submission, $fileOnDisk),
            'canOverrideApprove' => ! $submission->isArchived(),
            'canOverrideReject' => ! $submission->isArchived() && ! $submission->isLockedByPaidOrder(),
            'canArchive' => ! $submission->isArchived()
                && ! (($submission->isInUse() || $submission->isClaimedByAnotherOrder()) && ! $submission->isPublished()),
            'canRestore' => $submission->isArchived(),
        ]);
    }

    public function download(ContentSubmission $submission): StreamedResponse
    {
        $disk = Storage::disk($submission->disk ?: 'local');
        if (! $submission->path || ! $disk->exists($submission->path)) {
            abort(404, 'File not found');
        }

        $filename = str_replace(["\r", "\n", '"'], '', basename((string) ($submission->original_filename ?: 'article.docx')));

        return $disk->download(
            $submission->path,
            $filename !== '' ? $filename : 'article.docx',
            ['Content-Type' => $submission->mime ?: 'application/octet-stream']
        );
    }

    public function retry(ContentSubmission $submission): RedirectResponse
    {
        try {
            $result = $this->staffActions->retry($submission);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: 'Could not re-evaluate this article.');
        }

        $status = (string) ($result['moderation_status'] ?? $submission->fresh()?->moderation_status);
        $message = trim((string) ($result['message'] ?? ''));

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

        try {
            $this->staffActions->override($submission, $data['decision'], $request->user(), $data['notes']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: 'Override failed.');
        }

        return back()->with('success', $this->overrideFlash($submission->fresh(), $data['decision']));
    }

    public function archive(ContentSubmission $submission): RedirectResponse
    {
        try {
            $this->staffActions->archive($submission);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: 'Could not archive this article.');
        }

        return back()->with('success', 'Article #'.$submission->id.' archived.');
    }

    public function restore(ContentSubmission $submission): RedirectResponse
    {
        $this->staffActions->restore($submission);

        return back()->with('success', 'Article #'.$submission->id.' restored from archive.');
    }

    /**
     * @return array{availability:string, language:string, country:string, search:string, user_id:int}
     */
    protected function parseFilters(Request $request): array
    {
        $availability = strtolower(trim(scalar_text($request->query('availability', ''))));
        $status = strtolower(trim(scalar_text($request->query('status', ''))));
        $language = strtolower(trim(scalar_text($request->query('language', ''))));
        $country = strtolower(trim(scalar_text($request->query('country', ''))));
        $search = trim(scalar_text($request->query('q', '')));
        $userId = (int) scalar_text($request->query('user_id', 0));

        $allowed = ['all', 'available', 'evaluating', 'in_progress', 'needs_fix', 'completed', 'expired', 'archived'];
        if (! in_array($availability, $allowed, true)) {
            $availability = $this->availabilityFromLegacyStatus($status);
        }

        if ($language === 'all') {
            $language = '';
        }
        if ($country === 'all') {
            $country = '';
        }

        return [
            'availability' => $availability,
            'language' => $language,
            'country' => $country,
            'search' => $search,
            'user_id' => $userId > 0 ? $userId : 0,
        ];
    }

    protected function availabilityFromLegacyStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'available',
            'pending', 'processing' => 'evaluating',
            'rejected', 'error', 'needs_improvement' => 'needs_fix',
            'expired' => 'expired',
            'archived' => 'archived',
            default => 'all',
        };
    }

    /**
     * @param  Builder<ContentSubmission>  $query
     * @param  array{availability:string, language:string, country:string, search:string, user_id:int}  $filters
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
        }
        if ($filters['search'] !== '') {
            $this->applySearch($query, $filters['search']);
        }
    }

    /**
     * @param  Builder<ContentSubmission>  $query
     */
    protected function applyAvailability(Builder $query, string $availability): void
    {
        if ($availability === 'archived') {
            $query->whereNotNull('archived_at');

            return;
        }

        $query->whereNull('archived_at');

        if ($availability === 'expired') {
            $query->expiredUnused();

            return;
        }

        $this->excludeExpiredUnused($query);

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
            $q->where(function ($active) {
                $active->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->orWhere(function ($owned) {
                $owned->withOpenOwnerOrder();
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
     * @param  array{availability:string, language:string, country:string, search:string, user_id:int}  $filters
     * @return array<string, int>
     */
    protected function availabilityCounts(array $filters): array
    {
        $base = ContentSubmission::query();
        if ($filters['language'] !== '') {
            $base->where('language', $filters['language']);
        }
        if ($filters['country'] !== '') {
            $base->where('country', $filters['country']);
        }
        if ($filters['user_id'] > 0) {
            $base->where('user_id', $filters['user_id']);
        }
        if ($filters['search'] !== '') {
            $this->applySearch($base, $filters['search']);
        }

        $active = (clone $base)->whereNull('archived_at');
        $this->excludeExpiredUnused($active);

        return [
            'all' => (int) (clone $active)->count(),
            'available' => (int) (clone $active)->checkoutReady()->count(),
            'evaluating' => (int) (clone $active)->evaluatingInLibrary()->count(),
            'in_progress' => (int) (clone $active)->inProgressInLibrary()->count(),
            'needs_fix' => (int) (clone $active)->needsLibraryFix()->count(),
            'completed' => (int) (clone $active)->withCurrentLivePlacement()->count(),
            'expired' => (int) (clone $base)->whereNull('archived_at')->expiredUnused()->count(),
            'archived' => (int) (clone $base)->whereNotNull('archived_at')->count(),
        ];
    }

    /**
     * @return list<string>
     */
    protected function marketCodes(string $column): array
    {
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
    }

    /**
     * @param  array{availability:string, language:string, country:string, search:string, user_id:int}  $filters
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
        if ($filters['user_id'] > 0) {
            $query['user_id'] = $filters['user_id'];
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

        if ($submission->isExpired() && ! $submission->isInUse()) {
            return false;
        }

        return $fileOnDisk || $submission->hasPreviewHtml();
    }
}
