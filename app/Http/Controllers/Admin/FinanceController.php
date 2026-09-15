<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\ActivityLogger;
use App\Services\Admin\FinanceOverviewService;
use App\Services\Billing\BillingRuleService;
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

    public const DOSSIER_SEARCH_LIMIT = 8;

    public function __construct(
        private FinanceOverviewService $finance,
        private BillingRuleService $billingRules,
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

        $data = $this->finance->overview($period);

        return view('admin.finance', [
            'data' => $data,
            'periodKey' => $period['key'],
            'dateFrom' => $input['date_from'] ?? null,
            'dateTo' => $input['date_to'] ?? null,
            'userQuery' => $userQuery,
            'userQueryTooShort' => $userQuery !== '' && mb_strlen($needle) < 2,
            'hasMoreMatches' => $hasMoreMatches,
            'userMatches' => $userMatches,
            'payoutRules' => $this->billingRules->snapshot(),
        ]);
    }

    /**
     * Browse wallet_transactions (global ledger).
     */
    public function ledger(Request $request)
    {
        $search = search_text($request->input('search'));
        $userId = (int) $request->input('user_id');
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
            ));
        }

        $with = ['user:id,name,email'];
        try {
            if (Schema::hasTable('wallets')) {
                $with[] = 'wallet:id,role_id';
            }
        } catch (\Throwable) {
            // Leftover Hostinger: list the ledger even if wallets is gone.
        }

        try {
            $transactions = $this->ledgerQuery($request)
                ->with($with)
                ->latest()
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
            'ledgerUser'
        ));
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
                    'reference',
                    'description',
                ]);
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        try {
            $query = $this->ledgerQuery($request)->with(['user:id,name,email']);
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
                    'reference',
                    'description',
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
                'user_id' => (int) $request->input('user_id') ?: null,
                'type' => is_string($request->input('type')) ? $request->input('type') : '',
                'direction' => is_string($request->input('direction')) ? $request->input('direction') : '',
                'search' => search_text($request->input('search')),
                'date_from' => is_string($request->input('date_from')) ? $request->input('date_from') : null,
                'date_to' => is_string($request->input('date_to')) ? $request->input('date_to') : null,
                'rows_exported' => min($matchCount, self::LEDGER_EXPORT_LIMIT),
                'truncated' => $matchCount > self::LEDGER_EXPORT_LIMIT,
            ]
        );

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

    private function ledgerQuery(Request $request): Builder
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

        $search = search_text($request->input('search'));
        $meaningful = $this->dossierSearchNeedle($search);
        if ($search !== '' && $meaningful === '') {
            // "%%" / "_" only — do not treat as no filter (that dumps the ledger).
            $query->whereRaw('0 = 1');
        } elseif ($meaningful !== '') {
            $like = like_contains($search);
            $query->where(function ($q) use ($like, $search) {
                $q->whereRaw('reference LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('description LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereHas('user', function ($sub) use ($like) {
                        $sub->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                            ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
                    });
                if ($this->isExactDigitId($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        $userId = (int) $request->input('user_id');
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
            ->where(function ($query) use ($like) {
                $query->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
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
