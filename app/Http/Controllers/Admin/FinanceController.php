<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Admin\FinanceOverviewService;
use App\Services\Orders\OrderClawbackService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public const LEDGER_EXPORT_LIMIT = 10000;

    public function __construct(
        private FinanceOverviewService $finance,
    ) {}

    /**
     * Finance hub: period totals, liability, cash vs internal, ops queues.
     */
    public function index(Request $request)
    {
        $userQuery = is_string($request->input('q')) ? trim($request->input('q')) : '';
        $userMatches = collect();

        if ($userQuery !== '') {
            $redirect = $this->redirectToDossierIfUnique($userQuery);
            if ($redirect) {
                return $redirect;
            }

            if (strlen($userQuery) >= 2) {
                $userMatches = $this->searchUsers($userQuery);
                if ($userMatches->count() === 1) {
                    return redirect()->route('admin.finance.user', $userMatches->first());
                }
            }
        }

        $periodKey = is_string($request->get('period')) ? $request->get('period') : null;
        $dateFrom = is_string($request->get('date_from')) ? $request->get('date_from') : null;
        $dateTo = is_string($request->get('date_to')) ? $request->get('date_to') : null;

        $period = $this->finance->resolvePeriod($periodKey, $dateFrom, $dateTo);

        $list = is_string($request->get('list')) ? $request->get('list') : null;
        if (! in_array($list, ['debt', 'wallets'], true)) {
            $list = null;
        }

        $data = $this->finance->overview($period, $list);

        return view('admin.finance', [
            'data' => $data,
            'periodKey' => $period['key'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'userQuery' => $userQuery,
            'userMatches' => $userMatches,
            'list' => $list,
        ]);
    }

    /**
     * Browse wallet_transactions (global ledger).
     */
    public function ledger(Request $request)
    {
        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        $userId = $this->intQueryId($request->input('user_id'));
        $ledgerUser = $userId > 0
            ? User::query()->whereKey($userId)->first(['id', 'name', 'email'])
            : null;
        $type = is_string($request->input('type')) ? $request->input('type') : '';
        $direction = is_string($request->input('direction')) ? $request->input('direction') : '';
        $dateFrom = is_string($request->input('date_from')) ? $request->input('date_from') : '';
        $dateTo = is_string($request->input('date_to')) ? $request->input('date_to') : '';

        $exportQuery = $this->scalarQuery($request, ['page']);
        $clearUserQuery = $exportQuery;
        unset($clearUserQuery['user_id']);

        $transactions = $this->ledgerQuery($request)
            ->with(['user:id,name,email', 'wallet:id,role_id'])
            ->latest()
            ->paginate(40)
            ->appends($exportQuery);

        $types = $this->ledgerTypes();

        return view('admin.finance-ledger', compact(
            'transactions',
            'types',
            'search',
            'ledgerUser',
            'type',
            'direction',
            'dateFrom',
            'dateTo',
            'exportQuery',
            'clearUserQuery'
        ));
    }

    /**
     * CSV of the current ledger filters (not the period summary).
     */
    public function ledgerExport(Request $request): StreamedResponse
    {
        $query = $this->ledgerQuery($request)->with(['user:id,name,email']);
        $filename = 'wallet-ledger-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id',
                'created_at',
                'user_id',
                'user_name',
                'user_email',
                'type',
                'direction',
                'amount',
                'bonus_amount',
                'balance_after',
                'reference',
                'description',
            ]);

            $exported = 0;
            $query->chunkById(500, function ($rows) use ($out, &$exported) {
                foreach ($rows as $tx) {
                    if ($exported >= self::LEDGER_EXPORT_LIMIT) {
                        return false;
                    }
                    fputcsv($out, [
                        $tx->id,
                        optional($tx->created_at)?->toDateTimeString(),
                        $tx->user_id,
                        $tx->user?->name,
                        $tx->user?->email,
                        $tx->type,
                        $tx->direction,
                        $tx->amount,
                        $tx->bonus_amount,
                        $tx->balance_after,
                        $tx->reference,
                        $tx->description,
                    ]);
                    $exported++;
                }

                return $exported < self::LEDGER_EXPORT_LIMIT;
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Per-user money dossier.
     */
    public function user(User $user)
    {
        $dossier = $this->finance->userDossier($user);

        return view('admin.finance-user', ['dossier' => $dossier]);
    }

    /**
     * Clear outstanding publisher clawback debt on a wallet.
     */
    public function clearDebt(Request $request, Wallet $wallet, OrderClawbackService $clawbacks)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ]);

        try {
            $cleared = $clawbacks->clearWalletDebt($wallet, $request->user(), $data['reason']);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Cleared €'.number_format($cleared, 2).' of wallet debt.',
                    'cleared' => $cleared,
                ]);
            }

            return back()->with('success', 'Cleared €'.number_format($cleared, 2).' of wallet debt.');
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => collect($e->errors())->flatten()->first() ?? 'Unable to clear debt.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withErrors($e->errors());
        }
    }

    /**
     * Period summary CSV for accounting.
     */
    public function export(Request $request): StreamedResponse
    {
        $period = $this->finance->resolvePeriod(
            is_string($request->get('period')) ? $request->get('period') : null,
            is_string($request->get('date_from')) ? $request->get('date_from') : null,
            is_string($request->get('date_to')) ? $request->get('date_to') : null
        );
        $rows = $this->finance->exportRows($period);
        $filename = 'finance-'.$period['key'].'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows, $period) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['period', 'section', 'metric', 'value']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $period['label'],
                    $row['section'],
                    $row['metric'],
                    $row['value'],
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return list<string>
     */
    private function ledgerTypes(): array
    {
        return [
            WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::TYPE_BONUS_CREDIT,
            WalletTransaction::TYPE_PURCHASE,
            WalletTransaction::TYPE_REFUND,
            WalletTransaction::TYPE_WITHDRAWAL,
            WalletTransaction::TYPE_ADJUSTMENT,
            WalletTransaction::TYPE_TRANSFER_OUT,
            WalletTransaction::TYPE_TRANSFER_IN,
            WalletTransaction::TYPE_ROLE_MOVE_OUT,
            WalletTransaction::TYPE_ROLE_MOVE_IN,
        ];
    }

    private function ledgerQuery(Request $request): Builder
    {
        $query = WalletTransaction::query();

        $type = is_string($request->input('type')) ? $request->input('type') : '';
        if ($type !== '' && in_array($type, $this->ledgerTypes(), true)) {
            $query->where('type', $type);
        }

        $direction = is_string($request->input('direction')) ? $request->input('direction') : '';
        if (in_array($direction, ['credit', 'debit'], true)) {
            $query->where('direction', $direction);
        }

        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($search, $like) {
                $q->where('reference', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('user', function ($sub) use ($like) {
                        $sub->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        $userId = $this->intQueryId($request->input('user_id'));
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $dates = validator(
            [
                'date_from' => is_string($request->input('date_from')) ? $request->input('date_from') : null,
                'date_to' => is_string($request->input('date_to')) ? $request->input('date_to') : null,
            ],
            [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]
        )->valid();
        if (! empty($dates['date_from'])) {
            $query->whereDate('created_at', '>=', $dates['date_from']);
        }
        if (! empty($dates['date_to'])) {
            $query->whereDate('created_at', '<=', $dates['date_to']);
        }

        return $query;
    }

    private function intQueryId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param  list<string>  $except
     * @return array<string, string|int|float>
     */
    private function scalarQuery(Request $request, array $except = []): array
    {
        $out = [];
        foreach ($request->query() as $key => $value) {
            if (! is_string($key) || in_array($key, $except, true)) {
                continue;
            }
            if (is_string($value) || is_int($value) || is_float($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function redirectToDossierIfUnique(string $userQuery): ?RedirectResponse
    {
        if (! ctype_digit($userQuery)) {
            return null;
        }

        $user = User::query()->whereKey((int) $userQuery)->first();

        return $user ? redirect()->route('admin.finance.user', $user) : null;
    }

    /**
     * @return Collection<int, User>
     */
    private function searchUsers(string $userQuery)
    {
        $escaped = addcslashes($userQuery, '%_\\');

        return User::query()
            ->where(function ($query) use ($escaped) {
                $query->where('name', 'like', '%'.$escaped.'%')
                    ->orWhere('email', 'like', '%'.$escaped.'%');
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email']);
    }
}
