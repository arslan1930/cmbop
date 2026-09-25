<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\ActivityLogger;
use App\Services\Admin\FinanceOverviewService;
use App\Services\Orders\OrderClawbackService;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public const LEDGER_EXPORT_LIMIT = 10000;

    public const DOSSIER_SEARCH_LIMIT = 50;

    public function __construct(
        private FinanceOverviewService $finance,
    ) {}

    /**
     * Finance hub: period totals, liability, cash vs internal, ops queues.
     */
    public function index(Request $request)
    {
        $userQuery = search_text($request->input('q'));
        $needle = $this->dossierSearchNeedle($userQuery);
        $userMatches = collect();
        $hasMoreMatches = false;

        if ($userQuery !== '') {
            $redirect = $this->redirectToDossierIfUnique($userQuery);
            if ($redirect) {
                return $redirect;
            }

            // Character length after stripping LIKE wildcards so "%@" / "é"
            // cannot bypass the 2-character floor (strlen is bytes).
            // The LIKE itself uses the raw query with escaped wildcards so
            // "foo_bar" still matches an underscore email.
            if (mb_strlen($needle) >= 2) {
                $fetched = $this->searchUsers($userQuery, self::DOSSIER_SEARCH_LIMIT + 1);
                if ($fetched->count() === 1) {
                    return redirect()->route('admin.finance.user', $fetched->first());
                }
                $hasMoreMatches = $fetched->count() > self::DOSSIER_SEARCH_LIMIT;
                $userMatches = $fetched->take(self::DOSSIER_SEARCH_LIMIT);
            }
        }

        $input = $this->validatedPeriodInput($request);
        $period = $this->finance->resolvePeriod(
            $input['period'] ?? null,
            $input['date_from'] ?? null,
            $input['date_to'] ?? null
        );

        $minWallet = max(0, (float) $request->input('min_wallet', 0));
        $data = $this->finance->overview(
            $period,
            $request->query('wallets') === 'all',
            $request->query('debt') === 'all',
            $minWallet
        );

        return view('admin.finance', [
            'data' => $data,
            'periodKey' => $period['key'],
            'dateFrom' => $input['date_from'] ?? null,
            'dateTo' => $input['date_to'] ?? null,
            'userQuery' => $userQuery,
            'userQueryTooShort' => $userQuery !== '' && mb_strlen($needle) < 2,
            'hasMoreMatches' => $hasMoreMatches,
            'userMatches' => $userMatches,
            'minWallet' => $minWallet,
        ]);
    }

    /**
     * Browse wallet_transactions (global ledger).
     */
    public function ledger(Request $request)
    {
        $search = search_text($request->input('search'));
        $userId = $this->ledgerUserId($request);
        $ledgerUser = $userId > 0
            ? User::query()->whereKey($userId)->first(['id', 'name', 'email'])
            : null;

        if (! $this->walletTransactionsAvailable()) {
            $transactions = new LengthAwarePaginator([], 0, 40);
            $transactions->withPath($request->url())->appends($request->query());
            $types = $this->ledgerTypes();

            return view('admin.finance-ledger', compact(
                'transactions',
                'types',
                'search',
                'ledgerUser'
            ) + [
                'dateError' => null,
                'totals' => ['count' => 0, 'by_currency' => []],
                'advertiserRoleId' => null,
                'publisherRoleId' => null,
                'exportLimited' => false,
            ]);
        }

        $with = ['user:id,name,email'];
        try {
            if (Schema::hasTable('wallets')) {
                $with[] = 'wallet:id,role_id';
            }
        } catch (\Throwable) {
            // Leftover Hostinger: list the ledger even if wallets is gone.
        }

        $dateError = null;
        $totals = ['count' => 0, 'by_currency' => []];
        try {
            app(\App\Services\Wallet\WalletLedgerService::class)->backfillWithdrawalStatuses();
            $filtered = $this->ledgerQuery($request, $dateError);
            $totals = $this->ledgerTotals($filtered);
            $transactions = $this->applyLedgerSort($filtered, $request)
                ->with($with)
                ->paginate(40)
                ->withQueryString();
        } catch (\Throwable $e) {
            report($e);
            $transactions = new LengthAwarePaginator([], 0, 40);
            $transactions->withPath($request->url())->appends($request->query());
        }

        $types = $this->ledgerTypes();

        return view('admin.finance-ledger', compact(
            'transactions',
            'types',
            'search',
            'ledgerUser',
            'dateError',
            'totals'
        ) + [
            'advertiserRoleId' => Wallet::advertiserRoleId(),
            'publisherRoleId' => Wallet::publisherRoleId(),
            'exportLimited' => ($totals['count'] ?? 0) > self::LEDGER_EXPORT_LIMIT,
        ]);
    }

    /**
     * CSV of the current ledger filters (not the period summary).
     */
    public function ledgerExport(Request $request): StreamedResponse
    {
        if (! $this->walletTransactionsAvailable()) {
            $filename = 'wallet-ledger-'.now()->format('Y-m-d-His').'.csv';

            return response()->streamDownload(function () {
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
                    'bonus_balance_after',
                    'currency',
                    'status',
                    'payment_method',
                    'wallet_id',
                    'wallet_role',
                    'reference',
                    'description',
                    'related_type',
                    'related_id',
                ]);
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        try {
            app(\App\Services\Wallet\WalletLedgerService::class)->backfillWithdrawalStatuses();
            $ignoredDateError = null;
            $exportWith = ['user:id,name,email'];
            if (Schema::hasTable('wallets')) {
                $exportWith[] = 'wallet:id,role_id';
            }
            $query = $this->ledgerQuery($request, $ignoredDateError)->with($exportWith);
            $matchCount = (clone $query)->count();
        } catch (\Throwable $e) {
            report($e);

            $filename = 'wallet-ledger-'.now()->format('Y-m-d-His').'.csv';

            return response()->streamDownload(function () {
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
                    'bonus_balance_after',
                    'currency',
                    'status',
                    'payment_method',
                    'wallet_id',
                    'wallet_role',
                    'reference',
                    'description',
                    'related_type',
                    'related_id',
                ]);
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }
        $filename = 'wallet-ledger-'.now()->format('Y-m-d-His').'.csv';

        ActivityLogger::tryLog(
            'finance.ledger_exported',
            ($request->user()?->name ?? 'Admin').' exported the wallet ledger ('.min($matchCount, self::LEDGER_EXPORT_LIMIT).' row(s)).',
            null,
            [
                'user_id' => $this->ledgerUserId($request) ?: null,
                'type' => is_string($request->input('type')) ? $request->input('type') : '',
                'direction' => is_string($request->input('direction')) ? $request->input('direction') : '',
                'search' => search_text($request->input('search')),
                'date_from' => is_string($request->input('date_from')) ? $request->input('date_from') : null,
                'date_to' => is_string($request->input('date_to')) ? $request->input('date_to') : null,
                'rows_exported' => min($matchCount, self::LEDGER_EXPORT_LIMIT),
                'truncated' => $matchCount > self::LEDGER_EXPORT_LIMIT,
            ]
        );

        $advertiserRoleId = Wallet::advertiserRoleId();
        $publisherRoleId = Wallet::publisherRoleId();

        return response()->streamDownload(function () use ($query, $advertiserRoleId, $publisherRoleId) {
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
                'bonus_balance_after',
                'currency',
                'status',
                'payment_method',
                'wallet_id',
                'wallet_role',
                'reference',
                'description',
                'related_type',
                'related_id',
            ]);

            $exported = 0;
            $query->chunkById(500, function ($rows) use ($out, &$exported, $advertiserRoleId, $publisherRoleId) {
                foreach ($rows as $tx) {
                    if ($exported >= self::LEDGER_EXPORT_LIMIT) {
                        return false;
                    }
                    $roleId = (int) ($tx->wallet?->role_id ?? 0);
                    $role = $roleId === (int) $advertiserRoleId ? 'advertiser' : ($roleId === (int) $publisherRoleId ? 'publisher' : '');
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
                        $tx->bonus_balance_after,
                        $tx->currency,
                        $tx->status,
                        $tx->payment_method,
                        $tx->wallet_id,
                        $role,
                        $tx->reference,
                        $tx->description,
                        $tx->related_type,
                        $tx->related_id,
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

            ActivityLogger::tryLog(
                'finance.debt_cleared',
                ($request->user()?->name ?? 'Admin').' cleared €'.number_format($cleared, 2).' of wallet debt',
                $wallet,
                [
                    'amount' => $cleared,
                    'wallet_id' => $wallet->id,
                    'user_id' => $wallet->user_id,
                    'reason' => $data['reason'],
                ],
                'Wallet #'.$wallet->id
            );

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
        } catch (\Throwable $e) {
            $message = UserFacingError::message($e, 'Unable to clear debt. Please try again.');
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 500);
            }

            return back()->with('error', $message);
        }
    }

    /**
     * Period summary CSV for accounting.
     */
    public function export(Request $request): StreamedResponse
    {
        $input = $this->validatedPeriodInput($request);
        $period = $this->finance->resolvePeriod(
            $input['period'] ?? null,
            $input['date_from'] ?? null,
            $input['date_to'] ?? null
        );
        $rows = $this->finance->exportRows($period);
        $filename = 'finance-'.$period['key'].'-'.now()->format('Y-m-d-His').'.csv';

        ActivityLogger::tryLog(
            'finance.period_exported',
            ($request->user()?->name ?? 'Admin').' exported the '.$period['label'].' finance summary.',
            null,
            [
                'period' => $period['key'] ?? null,
                'label' => $period['label'] ?? null,
                'date_from' => $input['date_from'] ?? null,
                'date_to' => $input['date_to'] ?? null,
                'rows_exported' => count($rows),
            ]
        );

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

    private function ledgerQuery(Request $request, ?string &$dateError = null): Builder
    {
        if (! $this->walletTransactionsAvailable()) {
            return WalletTransaction::query()->whereRaw('0 = 1');
        }

        $query = WalletTransaction::query();

        $type = is_string($request->input('type')) ? $request->input('type') : '';
        if ($type !== '' && in_array($type, $this->ledgerTypes(), true)) {
            $query->where('type', $type);
        }

        $direction = is_string($request->input('direction')) ? $request->input('direction') : '';
        if (in_array($direction, ['credit', 'debit'], true)) {
            $query->where('direction', $direction);
        }

        $wallet = search_text($request->input('wallet'));
        if (in_array($wallet, ['advertiser', 'publisher'], true) && Schema::hasTable('wallets')) {
            $roleId = $wallet === 'advertiser' ? Wallet::advertiserRoleId() : Wallet::publisherRoleId();
            if ($roleId) {
                $query->whereHas('wallet', fn ($q) => $q->where('role_id', $roleId));
            }
        }

        $search = search_text($request->input('search'));
        $meaningful = $this->dossierSearchNeedle($search);
        if ($search !== '' && $meaningful === '') {
            $query->whereRaw('0 = 1');
        } elseif ($meaningful !== '') {
            $like = like_contains($search);
            $query->where(function ($q) use ($like, $search) {
                $q->whereRaw('reference LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('description LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereHas('user', function ($sub) use ($like) {
                        $sub->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                            ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
                        foreach (['company_name', 'payout_paypal_email', 'payout_wise_email', 'payout_bank_account', 'payout_crypto_trx_wallet'] as $column) {
                            if (Schema::hasColumn('users', $column)) {
                                $sub->orWhereRaw($column.' LIKE ? ESCAPE ?', [$like, '\\']);
                            }
                        }
                    });
                if ($this->isExactDigitId($search)) {
                    $id = (int) $search;
                    $q->orWhere('id', $id);
                    if (Schema::hasColumn('wallet_transactions', 'wallet_id')) {
                        $q->orWhere('wallet_id', $id);
                    }
                    if (Schema::hasColumn('wallet_transactions', 'related_id')) {
                        $q->orWhere('related_id', $id);
                    }
                }
            });
        }

        $userId = $this->ledgerUserId($request);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $fromRaw = is_string($request->input('date_from')) ? trim($request->input('date_from')) : '';
        $toRaw = is_string($request->input('date_to')) ? trim($request->input('date_to')) : '';
        $fromOk = $fromRaw === '' || $this->isLedgerDay($fromRaw);
        $toOk = $toRaw === '' || $this->isLedgerDay($toRaw);
        if (! $fromOk || ! $toOk) {
            $dateError = 'Enter real dates.';
        } elseif ($fromRaw !== '' && $toRaw !== '' && $toRaw < $fromRaw) {
            $dateError = 'The to date must be on or after the from date.';
        } elseif ($request->boolean('finance') && ($fromRaw !== '' || $toRaw !== '')) {
            $this->finance->applyLedgerCreatedWindow($query, $fromRaw !== '' ? $fromRaw : null, $toRaw !== '' ? $toRaw : null);
        } else {
            if ($fromRaw !== '') {
                $query->whereDate('created_at', '>=', $fromRaw);
            }
            if ($toRaw !== '') {
                $query->whereDate('created_at', '<=', $toRaw);
            }
        }

        return $query;
    }

    private function applyLedgerSort(Builder $query, Request $request): Builder
    {
        $sort = search_text($request->input('sort'));
        if ($sort === 'oldest') {
            return $query->orderBy('created_at')->orderBy('id');
        }
        if ($sort === 'amount') {
            return $query->orderByDesc('amount')->orderByDesc('id');
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return array{count: int, by_currency: array<string, array{credit: float, debit: float}>}
     */
    private function ledgerTotals(Builder $query): array
    {
        $count = (clone $query)->count();
        $hasCurrency = Schema::hasColumn('wallet_transactions', 'currency');
        if ($hasCurrency) {
            $rows = (clone $query)
                ->selectRaw("COALESCE(NULLIF(currency, ''), 'EUR') as code, direction, SUM(amount) as total")
                ->groupByRaw("COALESCE(NULLIF(currency, ''), 'EUR'), direction")
                ->get();
        } else {
            $rows = (clone $query)
                ->selectRaw('direction, SUM(amount) as total')
                ->groupBy('direction')
                ->get();
        }

        $by = [];
        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row->code ?? 'EUR')));
            if ($code === '') {
                $code = 'EUR';
            }
            $by[$code] ??= ['credit' => 0.0, 'debit' => 0.0];
            $dir = $row->direction === 'credit' ? 'credit' : 'debit';
            $by[$code][$dir] = round((float) $row->total, 2);
        }
        ksort($by);

        return ['count' => $count, 'by_currency' => $by];
    }

    private function ledgerUserId(Request $request): int
    {
        $raw = $request->input('user_id');
        if (! is_scalar($raw) || ! $this->isExactDigitId((string) $raw)) {
            return 0;
        }

        return (int) $raw;
    }

    private function isLedgerDay(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        try {
            return \Carbon\Carbon::parse($value)->toDateString() === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function redirectToDossierIfUnique(string $userQuery): ?RedirectResponse
    {
        if (! $this->isExactDigitId($userQuery)) {
            return null;
        }

        $user = User::query()->whereKey((int) $userQuery)->first();

        return $user ? redirect()->route('admin.finance.user', $user) : null;
    }

    /**
     * True for "8", not "08" or an overflowing digit string.
     */
    private function isExactDigitId(string $value): bool
    {
        return ctype_digit($value) && (string) ((int) $value) === $value;
    }

    /**
     * LIKE wildcards in the typed query must not broaden the match.
     */
    private function dossierSearchNeedle(string $userQuery): string
    {
        return str_replace(['\\', '%', '_'], ['', '', ''], $userQuery);
    }

    /**
     * @return Collection<int, User>
     */
    private function searchUsers(string $needle, int $limit)
    {
        $like = like_contains($needle);

        return User::query()
            ->where(function ($query) use ($like, $needle) {
                $query->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
                foreach (['company_name', 'payout_paypal_email', 'payout_wise_email', 'payout_bank_account', 'payout_crypto_trx_wallet'] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $query->orWhereRaw($column.' LIKE ? ESCAPE ?', [$like, '\\']);
                    }
                }
                if (ctype_digit($needle) && (string) ((int) $needle) === $needle) {
                    $query->orWhere('id', (int) $needle);
                }
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email']);
    }

    /**
     * @return array{period: ?string, date_from: ?string, date_to: ?string}
     */
    private function validatedPeriodInput(Request $request): array
    {
        return validator(
            [
                'period' => search_text($request->input('period')) ?: null,
                'date_from' => search_text($request->input('date_from')) ?: null,
                'date_to' => search_text($request->input('date_to')) ?: null,
            ],
            [
                'period' => 'nullable|in:week,month,all',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]
        )->validate();
    }

    private function walletTransactionsAvailable(): bool
    {
        try {
            if (! Schema::hasTable('wallet_transactions')) {
                return false;
            }
            DB::table('wallet_transactions')->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
