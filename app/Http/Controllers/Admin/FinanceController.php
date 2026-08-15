<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Admin\FinanceOverviewService;
use App\Services\Orders\OrderClawbackService;
use Carbon\Carbon;
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
        $rawFrom = is_string($request->get('date_from')) ? $request->get('date_from') : null;
        $rawTo = is_string($request->get('date_to')) ? $request->get('date_to') : null;

        $period = $this->finance->resolvePeriod($periodKey, $rawFrom, $rawTo);
        [$boundFrom, $boundTo] = $this->finance->dateBounds($rawFrom, $rawTo);
        $dateFrom = $boundFrom?->toDateString();
        $dateTo = $boundTo?->toDateString();

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
        $type = is_string($request->input('type')) && in_array($request->input('type'), $this->ledgerTypes(), true)
            ? $request->input('type')
            : '';
        $direction = is_string($request->input('direction')) && in_array($request->input('direction'), ['credit', 'debit'], true)
            ? $request->input('direction')
            : '';
        $walletRole = $this->ledgerWalletRole($request);
        $paymentMethod = $this->ledgerPaymentMethod($request);
        $status = $this->ledgerStatus($request);
        $walletId = $this->intQueryId($request->input('wallet_id'));
        $ledgerWallet = $walletId > 0
            ? Wallet::query()->with(['user:id,name,email', 'role:id,name'])->whereKey($walletId)->first()
            : null;
        [$boundFrom, $boundTo] = $this->ledgerDateBounds($request);
        $dateFrom = $boundFrom?->toDateString() ?? '';
        $dateTo = $boundTo?->toDateString() ?? '';

        $exportQuery = array_filter([
            'search' => $search !== '' ? $search : null,
            'user_id' => $userId > 0 ? $userId : null,
            'type' => $type !== '' ? $type : null,
            'direction' => $direction !== '' ? $direction : null,
            'wallet_role' => $walletRole !== '' ? $walletRole : null,
            'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
            'status' => $status !== '' ? $status : null,
            'wallet_id' => $walletId > 0 ? $walletId : null,
            'date_from' => $dateFrom !== '' ? $dateFrom : null,
            'date_to' => $dateTo !== '' ? $dateTo : null,
        ], fn ($value) => $value !== null && $value !== '');
        $clearUserQuery = $exportQuery;
        unset($clearUserQuery['user_id']);
        $clearWalletQuery = $exportQuery;
        unset($clearWalletQuery['wallet_id']);
        $hasLedgerFilters = collect($exportQuery)->except(['user_id', 'wallet_id'])->isNotEmpty();
        $clearFiltersQuery = array_filter([
            'user_id' => $userId > 0 ? $userId : null,
            'wallet_id' => $walletId > 0 ? $walletId : null,
        ]);

        $filtered = $this->ledgerQuery($request);
        $totals = $this->ledgerTotals($filtered);
        $transactions = (clone $filtered)
            ->with(['user:id,name,email', 'wallet:id,role_id', 'wallet.role:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(40)
            ->appends($exportQuery);
        WalletTransaction::eagerLoadKnownRelated($transactions->getCollection());

        $types = $this->ledgerTypes();
        $typeLabels = [];
        foreach ($types as $txType) {
            $typeLabels[$txType] = WalletTransaction::typeLabelFor($txType);
        }
        $paymentMethods = $this->ledgerPaymentMethodOptions();
        $statuses = $this->ledgerStatuses();

        return view('admin.finance-ledger', compact(
            'transactions',
            'types',
            'typeLabels',
            'paymentMethods',
            'statuses',
            'search',
            'ledgerUser',
            'userId',
            'ledgerWallet',
            'walletId',
            'type',
            'direction',
            'walletRole',
            'paymentMethod',
            'status',
            'dateFrom',
            'dateTo',
            'totals',
            'hasLedgerFilters',
            'clearFiltersQuery',
            'exportQuery',
            'clearUserQuery',
            'clearWalletQuery'
        ));
    }

    /**
     * CSV of the current ledger filters (not the period summary).
     */
    public function ledgerExport(Request $request): StreamedResponse
    {
        $query = $this->ledgerQuery($request)->with([
            'user:id,name,email',
            'wallet:id,role_id',
            'wallet.role:id,name',
        ]);
        $limit = $this->ledgerExportLimit();
        $filename = 'wallet-ledger-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $request, $limit) {
            $out = fopen('php://output', 'w');
            $this->writeCsvRow($out, [
                'id',
                'created_at',
                'user_id',
                'user_name',
                'user_email',
                'wallet_id',
                'wallet_role',
                'type',
                'direction',
                'status',
                'payment_method',
                'amount',
                'bonus_amount',
                'balance_after',
                'reference',
                'description',
            ]);

            $exported = 0;
            $query->chunkByIdDesc(500, function ($rows) use ($out, &$exported, $limit) {
                WalletTransaction::eagerLoadKnownRelated($rows);
                foreach ($rows as $tx) {
                    if ($exported >= $limit) {
                        return false;
                    }
                    $this->writeCsvRow($out, [
                        $tx->id,
                        $this->csvCell(optional($tx->created_at)?->toDateTimeString()),
                        $tx->user_id,
                        $this->csvCell($tx->user?->name),
                        $this->csvCell($tx->user?->email),
                        $tx->wallet_id,
                        $this->csvCell($tx->walletRoleLabel()),
                        $this->csvCell($tx->type),
                        $this->csvCell($tx->direction),
                        $this->csvCell($tx->status),
                        $this->csvCell($tx->paymentMethodKey()),
                        $tx->amount,
                        $tx->bonus_amount,
                        $tx->balance_after,
                        $this->csvCell($tx->reference),
                        $this->csvCell($tx->description),
                    ]);
                    $exported++;
                }

                return $exported < $limit;
            });

            if ($exported >= $limit && $this->ledgerQuery($request)->count() > $limit) {
                $this->writeCsvRow($out, ['truncated', 'limit', $limit]);
            }

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
            $this->writeCsvRow($out, ['period', 'section', 'metric', 'value']);
            foreach ($rows as $row) {
                $this->writeCsvRow($out, [
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

        $walletRole = $this->ledgerWalletRole($request);
        if ($walletRole !== '') {
            $query->whereHas('wallet.role', function ($roleQuery) use ($walletRole) {
                $roleQuery->where('name', $walletRole);
            });
        }

        $paymentMethod = $this->ledgerPaymentMethod($request);
        if ($paymentMethod !== '') {
            $aliases = $this->ledgerPaymentMethodAliases($paymentMethod);
            $query->where(function ($q) use ($aliases) {
                $q->whereIn('payment_method', $aliases)
                    ->orWhereHasMorph('related', [DepositRequest::class, Withdrawal::class, Order::class], function ($sub) use ($aliases) {
                        $sub->whereIn('payment_method', $aliases);
                    });
            });
        }

        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        if ($search !== '') {
            $searchId = $this->intQueryId($search);
            $query->where(function ($q) use ($search, $searchId) {
                if (preg_match('/^\d+$/', $search)) {
                    if ($searchId > 0) {
                        $q->where('id', $searchId)
                            ->orWhere('user_id', $searchId)
                            ->orWhere('wallet_id', $searchId);
                    } else {
                        $q->whereRaw('0 = 1');
                    }

                    return;
                }

                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where('reference', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereIn('user_id', User::query()
                        ->where(function ($sub) use ($like) {
                            $sub->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        })
                        ->select('id'));
            });
        }

        $userId = $this->intQueryId($request->input('user_id'));
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $walletId = $this->intQueryId($request->input('wallet_id'));
        if ($walletId > 0) {
            $query->where('wallet_id', $walletId);
        }

        $status = $this->ledgerStatus($request);
        if ($status !== '') {
            $query->where('status', $status);
        }

        [$from, $to] = $this->ledgerDateBounds($request);
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    /**
     * @return array{count: int, credits: float, debits: float, net: float}
     */
    private function ledgerTotals(Builder $query): array
    {
        $row = (clone $query)->toBase()->selectRaw(
            'COUNT(*) as row_count, '.
            "COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) as credits, ".
            "COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) as debits"
        )->first();

        $credits = round((float) ($row?->credits ?? 0), 2);
        $debits = round((float) ($row?->debits ?? 0), 2);

        return [
            'count' => (int) ($row?->row_count ?? 0),
            'credits' => $credits,
            'debits' => $debits,
            'net' => round($credits - $debits, 2),
        ];
    }

    /**
     * Parsed from/to bounds. Inverted ranges are swapped so the form,
     * table, totals, and export all use the same window.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function ledgerDateBounds(Request $request): array
    {
        return $this->finance->dateBounds(
            is_string($request->input('date_from')) ? $request->input('date_from') : null,
            is_string($request->input('date_to')) ? $request->input('date_to') : null
        );
    }

    /**
     * @return list<string>
     */
    private function ledgerStatuses(): array
    {
        return ['pending', 'completed', 'cancelled'];
    }

    private function ledgerStatus(Request $request): string
    {
        $status = is_string($request->input('status')) ? strtolower(trim($request->input('status'))) : '';

        return in_array($status, $this->ledgerStatuses(), true) ? $status : '';
    }

    private function ledgerWalletRole(Request $request): string
    {
        $role = is_string($request->input('wallet_role')) ? strtolower(trim($request->input('wallet_role'))) : '';

        return in_array($role, ['advertiser', 'publisher'], true) ? $role : '';
    }

    private function ledgerPaymentMethod(Request $request): string
    {
        $method = is_string($request->input('payment_method')) ? strtolower(trim($request->input('payment_method'))) : '';
        $method = match ($method) {
            'stripe' => 'card',
            'bank_transfer' => 'bank',
            default => $method,
        };

        return array_key_exists($method, $this->ledgerPaymentMethodOptions()) ? $method : '';
    }

    /**
     * @return array<string, string>
     */
    private function ledgerPaymentMethodOptions(): array
    {
        return [
            'card' => 'Card',
            'bank' => 'Bank Transfer',
            'paypal' => 'PayPal',
            'wise' => 'Wise',
            'crypto' => 'Cryptocurrency',
            'wallet' => 'Wallet',
        ];
    }

    /**
     * @return list<string>
     */
    private function ledgerPaymentMethodAliases(string $method): array
    {
        return match ($method) {
            'card' => ['card', 'stripe'],
            'bank' => ['bank', 'bank_transfer'],
            default => [$method],
        };
    }

    /**
     * @param  resource  $out
     * @param  list<mixed>  $fields
     */
    private function writeCsvRow($out, array $fields): void
    {
        // Empty escape is RFC 4180. PHP's default "\" merges the next
        // columns when a cell ends with a backslash.
        fputcsv($out, $fields, ',', '"', '');
    }

    private function csvCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        // Excel/LibreOffice still treat whitespace- or newline-prefixed
        // = + - @ as formulas. Probe the first visible character.
        $probe = ltrim($value, " \t\r\n\x0B\x00");
        if ($probe !== '' && preg_match('/^[=+\-@]/', $probe)) {
            return "'".$value;
        }

        return $value;
    }

    private function ledgerExportLimit(): int
    {
        $configured = config('billing.ledger_export_limit');
        if (is_int($configured) || (is_string($configured) && ctype_digit($configured))) {
            return max(1, (int) $configured);
        }

        return self::LEDGER_EXPORT_LIMIT;
    }

    private function intQueryId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/^[1-9]\d*$/', $value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function redirectToDossierIfUnique(string $userQuery): ?RedirectResponse
    {
        $userId = $this->intQueryId($userQuery);
        if ($userId < 1) {
            return null;
        }

        $user = User::query()->whereKey($userId)->first();

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
