<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItemDispute;
use App\Models\ProblemReport;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Services\ActivityLogger;
use App\Services\CommunityInboxNotifier;
use App\Services\SiteClaimTransferService;
use App\Support\CatalogProblemReport;
use App\Support\CommunityInbox;
use App\Support\UserFacingError;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommunityFeedbackController extends Controller
{
    public function __construct(
        private SiteClaimTransferService $claimTransfers,
        private CommunityInboxNotifier $inboxNotifier,
    ) {}

    public function index(Request $request)
    {
        $q = search_text($request->get('q'));
        $tabs = CommunityInbox::TABS;
        $counts = [
            'problems' => $this->pendingInboxCount(ProblemReport::class),
            'suggestions' => $this->pendingInboxCount(Suggestion::class),
            'websites' => $this->pendingInboxCount(WebsiteSuggestion::class),
            'claims' => $this->pendingInboxCount(SiteClaim::class),
        ];

        $tabProvided = search_text($request->query('tab')) !== '';
        $tab = $tabProvided
            ? CommunityInbox::normalizeTab($request->query('tab'))
            : CommunityInbox::landingTab($counts);
        $status = CommunityInbox::normalizeStatus($tab, $request->get('status'));
        $statuses = CommunityInbox::statusesFor($tab);
        $kind = $tab === CommunityInbox::TAB_PROBLEMS ? CommunityInbox::normalizeKind($request->get('kind')) : '';
        $sort = CommunityInbox::normalizeSort($request->get('sort'));
        $stale = CommunityInbox::normalizeStale($request->get('stale'));
        $category = $tab === CommunityInbox::TAB_SUGGESTIONS ? search_text($request->get('category')) : '';
        $occupying = $tab === CommunityInbox::TAB_WEBSITES ? CommunityInbox::normalizeOccupying($request->get('occupying')) : '';
        $claimView = $tab === CommunityInbox::TAB_CLAIMS ? CommunityInbox::normalizeClaimView($request->get('claim')) : '';
        $filtered = $q !== '' || $status !== null || $kind !== '' || $stale || $category !== ''
            || $occupying !== '' || $claimView !== '' || $sort === 'oldest';
        $tabQueries = [];
        foreach (array_keys($tabs) as $key) {
            $tabQueries[$key] = CommunityInbox::tabQuery($key, $q, $request->get('status'));
        }

        $problems = $this->inboxPage(
            $request,
            $tab === 'problems',
            ProblemReport::class,
            'problems_page',
            function () use ($status, $q, $kind, $stale, $sort) {
                $query = ProblemReport::query()
                    ->with($this->inboxRelations(ProblemReport::class))
                    ->when($status, fn ($inner) => $inner->where('status', $status))
                    ->when($q !== '', function ($inner) use ($q) {
                        $inner->where(function ($search) use ($q) {
                            CommunityInbox::constrainSearch($search, ['subject', 'message', 'email', 'name', 'page_url'], $q, 'problem_reports');
                            $search->orWhereHas('user', fn ($u) => CommunityInbox::constrainSearch($u, ['name', 'email'], $q, 'users'));
                        });
                    });
                CommunityInbox::constrainProblemKind($query, $kind);
                if ($stale) {
                    CommunityInbox::constrainStalePending($query);
                }
                CommunityInbox::applyListSort($query, $sort);

                return $query->paginate(25, ['*'], 'problems_page')->withQueryString();
            }
        );

        $suggestions = $this->inboxPage(
            $request,
            $tab === 'suggestions',
            Suggestion::class,
            'suggestions_page',
            function () use ($status, $q, $stale, $sort, $category) {
                $query = Suggestion::query()
                    ->with($this->inboxRelations(Suggestion::class))
                    ->when($status, fn ($inner) => $inner->where('status', $status))
                    ->when($category !== '', fn ($inner) => $inner->where('category', $category))
                    ->when($q !== '', function ($inner) use ($q) {
                        $inner->where(function ($search) use ($q) {
                            CommunityInbox::constrainSearch($search, ['message', 'email', 'name', 'page_url'], $q, 'suggestions');
                            $search->orWhereHas('user', fn ($u) => CommunityInbox::constrainSearch($u, ['name', 'email'], $q, 'users'));
                        });
                    });
                if ($stale) {
                    CommunityInbox::constrainStalePending($query);
                }
                CommunityInbox::applyListSort($query, $sort);

                return $query->paginate(25, ['*'], 'suggestions_page')->withQueryString();
            }
        );

        $websites = $this->inboxPage(
            $request,
            $tab === 'websites',
            WebsiteSuggestion::class,
            'websites_page',
            function () use ($status, $q, $stale, $sort, $occupying) {
                $query = WebsiteSuggestion::query()
                    ->with($this->inboxRelations(WebsiteSuggestion::class))
                    ->when($status, fn ($inner) => $inner->where('status', $status))
                    ->when($q !== '', function ($inner) use ($q) {
                        $inner->where(function ($search) use ($q) {
                            CommunityInbox::constrainSearch($search, ['website_name', 'website_url', 'domain', 'notes'], $q, 'website_suggestions');
                            $search->orWhereHas('user', fn ($u) => CommunityInbox::constrainSearch($u, ['name', 'email'], $q, 'users'));
                        });
                    });
                CommunityInbox::constrainWebsiteOccupying($query, $occupying);
                if ($stale) {
                    CommunityInbox::constrainStalePending($query);
                }
                CommunityInbox::applyListSort($query, $sort);

                return $query->paginate(25, ['*'], 'websites_page')->withQueryString();
            }
        );

        $hasSites = $this->tableExists('sites');
        $hasRolePivot = $this->tableExists('roles') && $this->tableExists('role_user');
        $claims = $this->inboxPage(
            $request,
            $tab === 'claims',
            SiteClaim::class,
            'claims_page',
            function () use ($status, $q, $hasSites, $hasRolePivot, $stale, $sort, $claimView) {
                $query = SiteClaim::query()
                    ->with(array_values(array_filter([
                        $hasSites ? $this->claimSiteWith() : null,
                        $hasSites ? 'site.publisher:id,name,email' : null,
                        'claimer:id,name,email',
                        $hasRolePivot ? 'claimer.roles' : null,
                        SiteClaim::hasTableColumn('reviewed_by') ? 'reviewer:id,name' : null,
                    ])))
                    ->when($status, fn ($inner) => $inner->where('status', $status))
                    ->when($q !== '', function ($inner) use ($q, $hasSites) {
                        $inner->where(function ($search) use ($q, $hasSites) {
                            CommunityInbox::constrainSearch($search, ['website_name', 'domain', 'proof_message', 'contact_email'], $q, 'site_claims');
                            $search->orWhereHas('claimer', fn ($u) => CommunityInbox::constrainSearch($u, ['name', 'email'], $q, 'users'));
                            if ($hasSites) {
                                $search->orWhereHas('site', fn ($s) => CommunityInbox::constrainSearch($s, ['site_name', 'domain'], $q, 'sites'));
                            }
                        });
                    });
                if ($claimView === 'mismatch' && SiteClaim::hasTableColumn('name_matches')) {
                    $query->where('name_matches', false);
                }
                if ($claimView === 'unverified' && $hasSites) {
                    $query->whereHas('site', fn ($site) => $site->where(function ($v) {
                        $v->where('verified', false)->orWhereNull('verified');
                    }));
                }
                if ($claimView === 'blocked' && $hasSites && $this->tableExists('order_items')
                    && CommunityInbox::columnExists('orders', 'status')) {
                    $query->where(function ($blocked) {
                        $blocked->whereHas('site.orderItems', function ($items) {
                            if (CommunityInbox::columnExists('order_items', 'publisher_status')) {
                                $items->where(function ($status) {
                                    $status->whereNull('publisher_status')
                                        ->orWhereNotIn('publisher_status', ['completed', 'rejected']);
                                });
                            }
                            $items->whereHas('order', fn ($order) => $order->whereNotIn('status', ['cancelled', 'completed', 'refunded']));
                        });
                        if (OrderItemDispute::tableAvailable()) {
                            $blocked->orWhereHas('site.orderItems', function ($items) {
                                $items->whereHas('disputes', fn ($d) => $d->where('status', OrderItemDispute::STATUS_OPEN));
                            });
                        }
                    });
                }
                if ($stale) {
                    CommunityInbox::constrainStalePending($query);
                }
                CommunityInbox::applyListSort($query, $sort);

                return $query->paginate(25, ['*'], 'claims_page')->withQueryString();
            }
        );

        $catalogProblems = [];
        if ($tab === 'problems') {
            try {
                $catalogProblems = CatalogProblemReport::summariesFor($problems);
            } catch (\Throwable $e) {
                Log::warning('Failed to parse catalog problem reports: '.$e->getMessage());
            }
        }

        $occupyingSites = $tab === 'websites'
            ? CommunityInbox::occupyingSitesFor($websites)
            : [];

        $claims->getCollection()->each(function ($claim) use ($hasSites) {
            if (! $hasSites || ! $claim->relationLoaded('site')) {
                $claim->setRelation('site', null);
            }
        });

        $claimOpenOrders = [];
        $claimOpenDisputes = [];
        $claimContexts = [];
        $claimSiblingPending = [];
        $siteIds = $claims->getCollection()->pluck('site_id')->filter()->unique()->values();
        $pendingBySite = collect();
        if ($siteIds->isNotEmpty() && SiteClaim::tableAvailable()) {
            try {
                $pendingBySite = SiteClaim::query()
                    ->whereIn('site_id', $siteIds)
                    ->where('status', 'pending')
                    ->selectRaw('site_id, COUNT(*) as aggregate')
                    ->groupBy('site_id')
                    ->pluck('aggregate', 'site_id');
            } catch (\Throwable $e) {
                Log::warning('Failed to count sibling community claims: '.$e->getMessage());
            }
        }

        foreach ($claims as $claim) {
            try {
                $site = $claim->relationLoaded('site') ? $claim->site : null;
                if ($claim->status === 'pending' && $site) {
                    $claimOpenOrders[$claim->id] = $this->claimTransfers->openOrderItemsCount($site);
                    $claimOpenDisputes[$claim->id] = $this->claimTransfers->openDisputesCount($site);
                }
                $pendingOnSite = (int) ($pendingBySite[$claim->site_id] ?? 0);
                $claimSiblingPending[$claim->id] = max(0, $pendingOnSite - ($claim->status === 'pending' ? 1 : 0));
                $claimerRoles = ($hasRolePivot && $claim->claimer && $claim->claimer->relationLoaded('roles'))
                    ? $claim->claimer->roles
                    : null;
                $claimContexts[$claim->id] = [
                    'open_orders' => $claimOpenOrders[$claim->id] ?? 0,
                    'open_disputes' => $claimOpenDisputes[$claim->id] ?? 0,
                    'verified' => (bool) ($site?->verified),
                    'name_matches' => (bool) $claim->name_matches,
                    'claimer_has_publisher_role' => (bool) ($claimerRoles?->contains('name', 'publisher')),
                ];
            } catch (\Throwable $e) {
                Log::warning('Failed to build community claim context: '.$e->getMessage(), [
                    'claim_id' => $claim->id,
                ]);
                $claimContexts[$claim->id] = [
                    'open_orders' => 0,
                    'open_disputes' => 0,
                    'verified' => false,
                    'name_matches' => (bool) ($claim->name_matches ?? false),
                    'claimer_has_publisher_role' => false,
                ];
            }
        }

        $matchedUsers = $this->matchedUsersForGuestRows(
            $tab === CommunityInbox::TAB_PROBLEMS ? $problems : ($tab === CommunityInbox::TAB_SUGGESTIONS ? $suggestions : collect())
        );
        $suggestionCategories = collect();
        if ($tab === CommunityInbox::TAB_SUGGESTIONS && Suggestion::tableAvailable() && Suggestion::hasTableColumn('category')) {
            try {
                $suggestionCategories = Suggestion::query()
                    ->whereNotNull('category')
                    ->where('category', '!=', '')
                    ->distinct()
                    ->orderBy('category')
                    ->pluck('category');
            } catch (\Throwable) {
                $suggestionCategories = collect();
            }
        }

        return view('admin.community.index', compact(
            'tab',
            'tabs',
            'status',
            'statuses',
            'q',
            'kind',
            'sort',
            'stale',
            'category',
            'occupying',
            'claimView',
            'filtered',
            'tabQueries',
            'problems',
            'catalogProblems',
            'suggestions',
            'websites',
            'claims',
            'counts',
            'claimOpenOrders',
            'claimOpenDisputes',
            'claimContexts',
            'claimSiblingPending',
            'occupyingSites',
            'matchedUsers',
            'suggestionCategories'
        ));
    }

    public function updateProblem(Request $request, int $id)
    {
        $report = ProblemReport::findAvailable($id);
        if (! $report) {
            abort(404);
        }

        return $this->updateStatus(
            $report,
            $request,
            'problem.report_updated',
            CommunityInbox::TAB_PROBLEMS
        );
    }

    public function updateSuggestion(Request $request, int $id)
    {
        $suggestion = Suggestion::findAvailable($id);
        if (! $suggestion) {
            abort(404);
        }

        return $this->updateStatus(
            $suggestion,
            $request,
            'suggestion.updated',
            CommunityInbox::TAB_SUGGESTIONS
        );
    }

    public function updateWebsiteSuggestion(Request $request, int $id)
    {
        $suggestion = WebsiteSuggestion::findAvailable($id);
        if (! $suggestion) {
            abort(404);
        }

        return $this->updateStatus(
            $suggestion,
            $request,
            'website.suggestion_updated',
            CommunityInbox::TAB_WEBSITES
        );
    }

    public function approveClaim(Request $request, int $id)
    {
        $claim = SiteClaim::findAvailable($id);
        if (! $claim) {
            abort(404);
        }
        $this->loadClaimSite($claim);
        if ($claim->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This claim was already reviewed.'], 422);
        }

        $data = $request->validate([
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $this->claimTransfers->approve($claim, $request->user(), $data['admin_notes'] ?? null);
        } catch (ValidationException $e) {
            $site = $claim->relationLoaded('site') ? $claim->site : null;

            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'This claim could not be approved.',
                'open_orders' => $site
                    ? $this->claimTransfers->openOrderItemsCount($site)
                    : 0,
                'open_disputes' => $site
                    ? $this->claimTransfers->openDisputesCount($site)
                    : 0,
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'This claim could not be approved.'),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Claim approved. Listing ownership transferred to the claimer.',
        ]);
    }

    public function rejectClaim(Request $request, int $id)
    {
        $claim = SiteClaim::findAvailable($id);
        if (! $claim) {
            abort(404);
        }
        $this->loadClaimSite($claim);
        if ($claim->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This claim was already reviewed.'], 422);
        }

        $data = $request->validate([
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $this->claimTransfers->reject($claim, $request->user(), $data['admin_notes'] ?? null);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'This claim could not be rejected.',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'This claim could not be rejected.'),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Claim rejected.',
        ]);
    }

    private function updateStatus($model, Request $request, string $activityType, string $tab)
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(CommunityInbox::statusesFor($tab))],
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $goingPending = $data['status'] === 'pending';
        $leavingPending = $model->status === 'pending' && ! $goingPending;
        $newNotes = $data['admin_notes'] ?? $model->admin_notes;
        if ($data['status'] === $model->status
            && (string) ($newNotes ?? '') === (string) ($model->admin_notes ?? '')) {
            return response()->json([
                'success' => true,
                'message' => 'Updated.',
                'item' => $this->freshCommunityItem($model),
            ]);
        }

        $payload = $model::attributesThatExist([
            'status' => $data['status'],
            'admin_notes' => $data['admin_notes'] ?? $model->admin_notes,
            'reviewed_at' => $goingPending ? null : now(),
            'reviewed_by' => $goingPending
                ? null
                : ($leavingPending ? auth()->id() : ($model->reviewed_by ?: auth()->id())),
            'updated_at' => now(),
        ]);
        if (! array_key_exists('status', $payload)) {
            return response()->json([
                'success' => false,
                'message' => 'This item cannot be updated on this database.',
            ], 422);
        }

        $query = $model->newQuery()->whereKey($model->id);
        if ($leavingPending) {
            $query->where('status', 'pending');
        }

        try {
            $affected = $query->update($payload);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'This item could not be updated. Please try again.'),
            ], 500);
        }
        if ($leavingPending && $affected !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'This item was already reviewed.',
            ], 422);
        }

        try {
            $model->refresh();
            $model->loadMissing('user');
        } catch (\Throwable $e) {
            Log::warning('Failed to reload community item after status update: '.$e->getMessage(), [
                'tab' => $tab,
                'id' => $model->id,
            ]);
            $model->forceFill($payload);
        }

        try {
            $noun = match ($activityType) {
                'problem.report_updated' => 'problem report',
                'suggestion.updated' => 'suggestion',
                'website.suggestion_updated' => 'website suggestion',
                default => 'inbox item',
            };
            ActivityLogger::log(
                $activityType,
                (auth()->user()?->name ?? 'Staff').' updated '.$noun.' #'.$model->id,
                $model,
                $data
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to log community status update: '.$e->getMessage(), [
                'tab' => $tab,
                'id' => $model->id,
            ]);
        }

        if ($leavingPending) {
            try {
                $this->inboxNotifier->notifySubmitterReviewed($model, $tab);
            } catch (\Throwable $e) {
                Log::warning('Failed to notify community submitter: '.$e->getMessage(), [
                    'tab' => $tab,
                    'id' => $model->id,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Updated.',
            'item' => $this->freshCommunityItem($model),
        ]);
    }

    private function freshCommunityItem($model)
    {
        try {
            return $model->fresh(['user:id,name,email', 'reviewer:id,name']) ?? $model;
        } catch (\Throwable) {
            return $model;
        }
    }

    /**
     * @param  class-string  $model
     */
    private function matchedUsersForGuestRows(mixed $page): array
    {
        $matched = [];
        try {
            $items = $page instanceof Paginator
                ? $page->items()
                : (is_iterable($page) ? $page : []);
            $emails = [];
            foreach ($items as $item) {
                if ((int) ($item->user_id ?? 0) > 0) {
                    continue;
                }
                $email = CommunityInbox::validEmail($item->email ?? null);
                if ($email) {
                    $emails[strtolower($email)] = $email;
                }
            }
            if ($emails === [] || ! $this->tableExists('users')) {
                return [];
            }
            foreach (User::query()->whereIn('email', array_values($emails))->get(['id', 'name', 'email']) as $user) {
                $matched[strtolower((string) $user->email)] = $user;
            }
        } catch (\Throwable) {
            return [];
        }

        return $matched;
    }

    private function pendingInboxCount(string $model): int
    {
        try {
            if (! $model::tableAvailable() || ! $model::hasTableColumn('status')) {
                return 0;
            }

            return $model::where('status', 'pending')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  class-string  $model
     * @param  callable(): mixed  $build
     */
    private function inboxPage(Request $request, bool $active, string $model, string $pageName, callable $build)
    {
        if (! $active || ! $model::tableAvailable()) {
            return CommunityInbox::emptyPage($request, $pageName);
        }

        try {
            return $build();
        } catch (\Throwable $e) {
            Log::warning('Failed to load community inbox page: '.$e->getMessage(), [
                'page' => $pageName,
            ]);

            return CommunityInbox::emptyPage($request, $pageName);
        }
    }

    /**
     * @param  class-string  $model
     * @return list<string>
     */
    private function inboxRelations(string $model): array
    {
        $with = ['user:id,name,email'];
        if ($model::hasTableColumn('reviewed_by')) {
            $with[] = 'reviewer:id,name';
        }

        return $with;
    }

    private function claimSiteWith(): string
    {
        $columns = array_values(array_filter(
            ['id', 'site_name', 'domain', 'site_url', 'publisher_id', 'verified'],
            fn ($column) => CommunityInbox::columnExists('sites', $column)
        ));
        if ($columns === [] || ! in_array('id', $columns, true)) {
            return 'site';
        }

        return 'site:'.implode(',', $columns);
    }

    private function loadClaimSite(SiteClaim $claim): void
    {
        if (! $this->tableExists('sites')) {
            $claim->setRelation('site', null);

            return;
        }

        try {
            $claim->loadMissing('site');
        } catch (\Throwable $e) {
            Log::warning('Failed to load community claim site: '.$e->getMessage(), [
                'claim_id' => $claim->id,
            ]);
            $claim->setRelation('site', null);
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            if (! Schema::hasTable($table)) {
                return false;
            }
            // Schema::hasTable can stay true after a leftover DROP TABLE.
            DB::table($table)->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
