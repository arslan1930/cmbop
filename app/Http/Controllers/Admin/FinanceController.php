<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Admin\FinanceOverviewService;
use App\Services\Orders\OrderClawbackService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function __construct(
        private FinanceOverviewService $finance,
    ) {}

    /**
     * Finance hub: period totals, liability, cash vs internal, ops queues.
     */
    public function index(Request $request)
    {
        $dateFrom = scalar_text($request->get('date_from')) ?: null;
        $dateTo = scalar_text($request->get('date_to')) ?: null;
        $period = $this->finance->resolvePeriod(
            scalar_text($request->get('period')) ?: null,
            $dateFrom,
            $dateTo
        );

        $data = $this->finance->overview($period);

        return view('admin.finance', [
            'data' => $data,
            'periodKey' => $period['key'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    /**
     * Browse wallet_transactions (global ledger).
     */
    public function ledger(Request $request)
    {
        $query = WalletTransaction::with(['user:id,name,email', 'wallet:id,role_id'])
            ->latest();

        $type = scalar_text($request->type);
        if ($type !== '') {
            $query->where('type', $type);
        }
        $direction = scalar_text($request->direction);
        if ($direction !== '') {
            $query->where('direction', $direction);
        }
        if ($request->filled('search')) {
            $search = trim(scalar_text($request->search));
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('id', $search)
                    ->orWhereHas('user', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }
        $dateFrom = scalar_text($request->date_from);
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        $dateTo = scalar_text($request->date_to);
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $transactions = $query->paginate(40)->withQueryString();

        $types = [
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

        return view('admin.finance-ledger', compact('transactions', 'types'));
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
            scalar_text($request->get('period')) ?: null,
            scalar_text($request->get('date_from')) ?: null,
            scalar_text($request->get('date_to')) ?: null
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
}
