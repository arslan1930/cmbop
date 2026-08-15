<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Withdrawal;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Services\Billing\WithdrawalPayoutStatementService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $userId = (int) auth()->id();
        $ownedRefs = Withdrawal::query()
            ->where('user_id', $userId)
            ->pluck('id')
            ->map(fn ($id) => 'WD-'.$id)
            ->all();

        $query = Invoice::query()
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->where(function ($q) use ($userId, $ownedRefs) {
                if ($ownedRefs !== []) {
                    $q->whereIn('reference_code', $ownedRefs);
                }
                $q->orWhere(function ($legacy) use ($userId) {
                    $legacy->where('user_id', $userId)
                        ->where(function ($ref) {
                            $ref->whereNull('reference_code')
                                ->orWhere('reference_code', '')
                                ->orWhere('reference_code', 'not like', 'WD-%');
                        });
                });
            });

        $search = search_text($request->input('search'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%");
            });
        }

        $from = $this->parseDate($request->input('from'));
        $to = $this->parseDate($request->input('to'));

        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from) {
            $query->whereDate('invoice_date', '>=', $from->toDateString());
        }

        if ($to) {
            $query->whereDate('invoice_date', '<=', $to->toDateString());
        }

        $documents = $query->latest('invoice_date')->latest('id')->paginate(20)->withQueryString();

        return view('publisher.billing.index', [
            'documents' => $documents,
            'filterFrom' => $from?->toDateString(),
            'filterTo' => $to?->toDateString(),
        ]);
    }

    public function show(Invoice $invoice, WithdrawalPayoutStatementService $statements)
    {
        $this->authorizeOwner($invoice);
        abort_unless($invoice->type === Invoice::TYPE_WITHDRAWAL_PAYOUT, 404);
        abort_if($invoice->status === Invoice::STATUS_CANCELLED, 404);
        $invoice = $statements->reconcileInvoice($invoice);

        return response()
            ->view('publisher.billing.show', compact('invoice'))
            ->header('Cache-Control', 'no-store');
    }

    public function download(
        Invoice $invoice,
        InvoicePdfGenerator $pdfs,
        BillingDocumentService $billing,
        WithdrawalPayoutStatementService $statements,
    ) {
        $this->authorizeOwner($invoice);
        abort_unless($invoice->type === Invoice::TYPE_WITHDRAWAL_PAYOUT, 404);
        abort_if($invoice->status === Invoice::STATUS_CANCELLED, 404);

        $invoice = $statements->reconcileInvoice($invoice);
        // normalizeLegacyFeeLineItems() clears pdf_path when it strips legacy fee lines.
        $invoice = $statements->normalizeLegacyFeeLineItems($invoice);

        if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
            try {
                $pdfs->generateAndStore($invoice);
                $invoice->refresh();
            } catch (\Throwable $e) {
                report($e);
                // Fall through — download() can still render a live PDF.
            }
        }

        $billing->recordDownload($invoice);

        return $pdfs->download($invoice);
    }

    public function viewPdf(
        Invoice $invoice,
        InvoicePdfGenerator $pdfs,
        BillingDocumentService $billing,
        WithdrawalPayoutStatementService $statements,
    ) {
        $this->authorizeOwner($invoice);
        abort_unless($invoice->type === Invoice::TYPE_WITHDRAWAL_PAYOUT, 404);
        abort_if($invoice->status === Invoice::STATUS_CANCELLED, 404);

        $invoice = $statements->reconcileInvoice($invoice);
        $invoice = $statements->normalizeLegacyFeeLineItems($invoice);

        if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
            try {
                $pdfs->generateAndStore($invoice);
                $invoice->refresh();
            } catch (\Throwable $e) {
                report($e);
                // Fall through — stream() can still render a live PDF.
            }
        }

        $billing->recordDownload($invoice);

        return $pdfs->stream($invoice);
    }

    private function authorizeOwner(Invoice $invoice): void
    {
        $user = auth()->user();
        if ($user?->isAdmin()) {
            return;
        }

        $userId = (int) auth()->id();
        if ($invoice->isWithdrawalPayout()) {
            $withdrawalId = $invoice->withdrawalId();
            if ($withdrawalId > 0) {
                if (Withdrawal::query()->whereKey($withdrawalId)->where('user_id', $userId)->exists()) {
                    return;
                }
                if (Withdrawal::query()->whereKey($withdrawalId)->exists()) {
                    abort(403);
                }
            }
        }

        if ($userId > 0 && (int) $invoice->user_id === $userId) {
            return;
        }

        abort(403);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = search_text($value);
        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $raw));
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day)->startOfDay();
    }
}
