<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ActivityLogDateBounds;
use App\Support\ActivityLogTextSearch;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    /**
     * Admin-only append-only history of actions that call ActivityLogger.
     * Marketers use /marketing/history.
     */
    public const EXPORT_LIMIT = 10000;

    /** @var list<string> */
    public const ROLES = ['admin', 'marketing', 'publisher', 'advertiser'];

    public function index(Request $request)
    {
        try {
            return $this->renderIndex($request);
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load activity history. Please refresh and try again.')
            );

            return $this->emptyIndexView($request);
        }
    }

    private function renderIndex(Request $request): View|RedirectResponse
    {
        [$query, $meta] = $this->filteredQuery($request);

        $actionCounts = (clone $query)
            ->selectRaw('action, COUNT(*) as aggregate')
            ->groupBy('action')
            ->pluck('aggregate', 'action');

        $this->applySelectedAction($query, $meta['selectedAction']);

        $logs = $query->latest('id')->paginate(25)->withQueryString();

        if ($request->integer('page') > 1 && $logs->total() > 0 && $logs->count() === 0) {
            return redirect()->to($logs->url(max(1, $logs->lastPage())));
        }

        $collapsedCounts = [];
        foreach ($actionCounts as $code => $total) {
            $canonical = activity_action_canonical((string) $code);
            $collapsedCounts[$canonical] = ($collapsedCounts[$canonical] ?? 0) + (int) $total;
        }
        $actionCounts = collect($collapsedCounts);

        $actions = array_keys(activity_action_labels());
        foreach ($actionCounts->keys() as $code) {
            if (is_string($code) && $code !== '' && ! in_array($code, $actions, true)) {
                $actions[] = $code;
            }
        }
        sort($actions);

        $exportQuery = $this->filterQueryParams($request);
        $exportLimit = $this->exportLimit();
        $exportCapped = $logs->total() > $exportLimit;

        return view('admin.activity-logs', array_merge($meta, compact(
            'logs',
            'actions',
            'actionCounts',
            'exportQuery',
            'exportCapped',
            'exportLimit'
        )));
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        try {
            [$query, $meta] = $this->filteredQuery($request);

            if ($meta['dateErrors'] !== []) {
                return redirect()
                    ->route('admin.activity-logs.index', $this->filterQueryParams($request))
                    ->with('error', implode(' ', $meta['dateErrors']));
            }

            $this->applySelectedAction($query, $meta['selectedAction']);

            $limit = $this->exportLimit();
            if ((clone $query)->count() > $limit) {
                return redirect()
                    ->route('admin.activity-logs.index', $this->filterQueryParams($request))
                    ->with('error', 'More than '.number_format($limit).' events match. Narrow the filters before exporting — a partial CSV would look complete.');
            }

            $rows = $query->latest('id')->limit($limit)->get();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.activity-logs.index', $this->filterQueryParams($request))
                ->with('error', UserFacingError::message($e, 'We could not export activity history. Please try again.'));
        }

        $filename = 'activity-logs-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'when',
                'user_name',
                'user_email',
                'role',
                'action',
                'subject',
                'description',
                'properties',
                'ip_address',
            ]);

            foreach ($rows as $log) {
                fputcsv($out, [
                    $this->csvCell(optional($log->created_at)?->toIso8601String()),
                    $this->csvCell($log->user_name),
                    $this->csvCell($log->user_email),
                    $this->csvCell($log->role),
                    $this->csvCell(activity_action_canonical($log->action)),
                    $this->csvCell($log->subject_label),
                    $this->csvCell($log->description),
                    $this->csvCell(is_array($log->properties) ? json_encode($log->properties) : $log->properties),
                    $this->csvCell($log->ip_address),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function emptyIndexView(Request $request): View
    {
        $selectedAction = search_text($request->input('action'));
        if ($selectedAction !== '' && ! preg_match('/^[a-z0-9_.]+$/', $selectedAction)) {
            $selectedAction = '';
        }
        if ($selectedAction !== '') {
            $selectedAction = activity_action_canonical($selectedAction);
        }

        $selectedRole = search_text($request->input('role'));
        if (! in_array($selectedRole, self::ROLES, true)) {
            $selectedRole = '';
        }

        return view('admin.activity-logs', [
            'logs' => new LengthAwarePaginator([], 0, 25, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]),
            'actions' => array_keys(activity_action_labels()),
            'actionCounts' => collect(),
            'exportQuery' => $this->filterQueryParams($request),
            'exportCapped' => false,
            'exportLimit' => $this->exportLimit(),
            'dateErrors' => [],
            'filtersActive' => $this->filterQueryParams($request) !== [],
            'selectedAction' => $selectedAction,
            'selectedRole' => $selectedRole,
            'filterUserId' => (int) (filter_number($request->input('user_id')) ?? 0) ?: null,
        ]);
    }

    /**
     * @return array{0: Builder<ActivityLog>, 1: array{
     *     dateErrors: list<string>,
     *     filtersActive: bool,
     *     selectedAction: string,
     *     selectedRole: string,
     *     filterUserId: int|null
     * }}
     */
    private function filteredQuery(Request $request): array
    {
        $query = ActivityLog::query();

        $dateErrors = ActivityLogDateBounds::apply(
            $query,
            $request->input('from'),
            $request->input('to')
        );

        $term = search_text($request->input('user'));
        $userId = (int) (filter_number($request->input('user_id')) ?? 0);
        if ($term !== '') {
            $like = like_contains($term);
            $query->where(function ($q) use ($like, $term) {
                $q->whereRaw('user_name LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('user_email LIKE ? ESCAPE ?', [$like, '\\']);
                if (ctype_digit($term) && (string) ((int) $term) === $term) {
                    $q->orWhere('user_id', (int) $term);
                }
            });
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $selectedRole = search_text($request->input('role'));
        if (! in_array($selectedRole, self::ROLES, true)) {
            $selectedRole = '';
        }
        if ($selectedRole !== '') {
            $query->where('role', $selectedRole);
        }

        $needle = search_text($request->input('q'));
        if ($needle !== '') {
            $like = like_contains($needle);
            $matchedActions = activity_action_actions_matching($needle);
            $query->where(function ($q) use ($like, $needle, $matchedActions) {
                $q->whereRaw('subject_label LIKE ? ESCAPE ?', [$like, '\\']);
                $q->orWhere(function ($inner) use ($needle) {
                    ActivityLogTextSearch::whereDescriptionHasWord($inner, $needle);
                });
                if ($matchedActions !== []) {
                    $q->orWhereIn('action', $matchedActions);
                }
            });
        }

        $selectedAction = search_text($request->input('action'));
        if ($selectedAction !== '' && ! preg_match('/^[a-z0-9_.]+$/', $selectedAction)) {
            $selectedAction = '';
        }
        if ($selectedAction !== '') {
            $selectedAction = activity_action_canonical($selectedAction);
        }

        $filtersActive = $term !== ''
            || $userId > 0
            || $selectedRole !== ''
            || $needle !== ''
            || $selectedAction !== ''
            || $request->filled('from')
            || $request->filled('to');

        return [
            $query,
            [
                'dateErrors' => $dateErrors,
                'filtersActive' => $filtersActive,
                'selectedAction' => $selectedAction,
                'selectedRole' => $selectedRole,
                'filterUserId' => $userId > 0 ? $userId : null,
            ],
        ];
    }

    private function applySelectedAction(Builder $query, string $selectedAction): void
    {
        if ($selectedAction === '') {
            return;
        }

        $codes = activity_action_equivalent_codes($selectedAction);
        if ($codes === []) {
            return;
        }

        if (count($codes) === 1) {
            $query->where('action', $codes[0]);

            return;
        }

        $query->whereIn('action', $codes);
    }

    private function exportLimit(): int
    {
        $configured = (int) config('activity_logs.export_limit', self::EXPORT_LIMIT);

        return $configured > 0 ? $configured : self::EXPORT_LIMIT;
    }

    /**
     * @return array<string, string>
     */
    private function filterQueryParams(Request $request): array
    {
        $out = [];
        foreach (['user', 'q', 'action', 'role', 'from', 'to'] as $key) {
            $value = $request->input($key);
            if (is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }
        $userId = (int) (filter_number($request->input('user_id')) ?? 0);
        if ($userId > 0) {
            $out['user_id'] = $userId;
        }

        return $out;
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
