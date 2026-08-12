<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Services\Billing\WithdrawalPayoutStatementService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query()
            ->where('user_id', auth()->id())
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED);

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
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

    public function show(Invoice $invoice)
    {
        $this->authorizeOwner($invoice);
        abort_unless($invoice->type === Invoice::TYPE_WITHDRAWAL_PAYOUT, 404);
        abort_if($invoice->status === Invoice::STATUS_CANCELLED, 404);

        return view('publisher.billing.show', compact('invoice'));
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

        $beforeItems = json_encode($invoice->line_items ?? []);
        $invoice = $statements->normalizeLegacyFeeLineItems($invoice);
        $lineItemsChanged = $beforeItems !== json_encode($invoice->line_items ?? []);

        if ($lineItemsChanged || ! $invoice->hasPdf() || ! $invoice->pdfExists()) {
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

        $beforeItems = json_encode($invoice->line_items ?? []);
        $invoice = $statements->normalizeLegacyFeeLineItems($invoice);
        $lineItemsChanged = $beforeItems !== json_encode($invoice->line_items ?? []);

        if ($lineItemsChanged || ! $invoice->hasPdf() || ! $invoice->pdfExists()) {
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
        if ((int) $invoice->user_id !== (int) auth()->id() && ! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $raw));
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day)->startOfDay();
    }
}
