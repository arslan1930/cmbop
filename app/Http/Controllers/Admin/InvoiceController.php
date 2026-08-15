<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingEvent;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Services\Billing\WithdrawalPayoutStatementService;
use App\Support\UserFacingError;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query()->with(['user:id,name,email', 'order:id,order_number']);

        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"));
            });
        }

        $allowedStatuses = [
            Invoice::STATUS_PAID,
            Invoice::STATUS_ISSUED,
            Invoice::STATUS_PENDING,
            Invoice::STATUS_FAILED,
            Invoice::STATUS_REFUNDED,
            Invoice::STATUS_CANCELLED,
        ];
        if ($request->filled('status') && in_array($request->status, $allowedStatuses, true)) {
            $query->where('status', $request->status);
        }

        $allowedTypes = [
            Invoice::TYPE_TAX_INVOICE,
            Invoice::TYPE_PAYMENT_RECEIPT,
            Invoice::TYPE_REFUND_RECEIPT,
            Invoice::TYPE_PAYMENT_FAILURE,
            Invoice::TYPE_DEPOSIT_RECEIPT,
            Invoice::TYPE_WITHDRAWAL_PAYOUT,
        ];
        if ($request->filled('type') && in_array($request->type, $allowedTypes, true)) {
            $query->where('type', $request->type);
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

        $invoices = $query->latest('invoice_date')->latest('id')->paginate(25)->withQueryString();

        $stats = [
            'documents' => Invoice::count(),
            'tax_invoices' => Invoice::where('type', Invoice::TYPE_TAX_INVOICE)->count(),
            'downloaded' => (int) Invoice::sum('download_count'),
            'emailed' => (int) Invoice::sum('email_count'),
            'failures' => BillingEvent::where('event_type', 'invoice_generation_failed')->count(),
            'payment_failures' => Invoice::where('type', Invoice::TYPE_PAYMENT_FAILURE)->count(),
            'refunds' => Invoice::where('type', Invoice::TYPE_REFUND_RECEIPT)->count(),
            'deposits' => Invoice::where('type', Invoice::TYPE_DEPOSIT_RECEIPT)->count(),
            'payouts' => Invoice::where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)->count(),
        ];

        return view('admin.invoices.index', [
            'invoices' => $invoices,
            'stats' => $stats,
            'filterSearch' => $search,
            'filterFrom' => $from?->toDateString(),
            'filterTo' => $to?->toDateString(),
            'currencySymbol' => (string) config('billing.currency_symbol', '€'),
        ]);
    }

    public function show(Invoice $invoice, WithdrawalPayoutStatementService $statements)
    {
        $invoice = $statements->reconcileInvoice($invoice);
        $invoice->load([
            'user:id,name,email',
            'order:id,order_number',
            'parentInvoice',
            'childInvoices',
            'cancelledBy:id,name,email',
            'events' => fn ($q) => $q->latest()->limit(30),
        ]);

        return view('admin.invoices.show', [
            'invoice' => $invoice,
            'relatedUrl' => $invoice->relatedAdminUrl(),
            'currencySymbol' => (string) config('billing.currency_symbol', '€'),
        ]);
    }

    public function viewPdf(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing, WithdrawalPayoutStatementService $statements)
    {
        $invoice = $statements->reconcileInvoice($invoice);
        $invoice = $statements->normalizeLegacyFeeLineItems($invoice);
        try {
            if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
                $pdfs->generateAndStore($invoice);
                $invoice->refresh();
            }
        } catch (\Throwable $e) {
            report($e);
            // Fall through — stream() can still render a live PDF after
            // identity repair cleared pdf_path.
        }

        $billing->recordAdminDownload($invoice, auth()->user());

        return $pdfs->stream($invoice);
    }

    public function download(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing, WithdrawalPayoutStatementService $statements)
    {
        $invoice = $statements->reconcileInvoice($invoice);
        $invoice = $statements->normalizeLegacyFeeLineItems($invoice);
        try {
            if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
                $pdfs->generateAndStore($invoice);
                $invoice->refresh();
            }
        } catch (\Throwable $e) {
            report($e);
            // Fall through — download() can still render a live PDF after
            // identity repair cleared pdf_path.
        }

        $billing->recordAdminDownload($invoice, auth()->user());

        return $pdfs->download($invoice);
    }

    public function resend(Invoice $invoice, BillingDocumentService $billing)
    {
        $result = $billing->resendInvoiceEmail($invoice);

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        return back()->with('success', $result['message']);
    }

    public function cancel(Request $request, Invoice $invoice, BillingDocumentService $billing)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($invoice->type !== Invoice::TYPE_TAX_INVOICE) {
            return back()->with('error', 'Only tax invoices can be cancelled.');
        }

        $billing->cancelInvoice($invoice, auth()->user(), $data['reason'] ?? null);

        return back()->with('success', 'Invoice cancelled. The PDF is retained for audit.');
    }

    public function generate(Request $request, BillingDocumentService $billing)
    {
        $data = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
        ]);

        $order = Order::with(['user', 'items'])->findOrFail($data['order_id']);

        try {
            $invoice = $billing->generateManually($order, auth()->user());
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not generate the invoice.'));
        }

        $redirect = redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', 'Invoice '.$invoice->invoice_number.' generated.');

        if ($order->payment_status !== 'paid') {
            $redirect->with('warning', 'Invoice issued for an unpaid order.');
        }

        return $redirect;
    }

    /**
     * Ops: backfill tax invoices for paid orders that never got one.
     */
    public function backfillMissing(Request $request, BillingDocumentService $billing)
    {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $result = $billing->backfillMissingTaxInvoices((int) ($data['limit'] ?? 50));

        return back()->with(
            'success',
            sprintf(
                'Backfill complete: %d tax invoices created, %d skipped, %d failed. Payment receipts are not backfilled.',
                $result['created'],
                $result['skipped'],
                $result['failed']
            )
        );
    }

    /**
     * Ops: regenerate PDFs that are missing on disk.
     */
    public function regenerateMissingPdfs(Request $request, BillingDocumentService $billing)
    {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $result = $billing->regenerateMissingPdfs((int) ($data['limit'] ?? 50));

        return back()->with(
            'success',
            sprintf(
                'PDF regenerate complete: %d regenerated, %d failed.',
                $result['regenerated'],
                $result['failed']
            )
        );
    }

    public function regeneratePdf(Invoice $invoice, BillingDocumentService $billing)
    {
        try {
            $billing->regeneratePdf($invoice);
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not regenerate the PDF.'));
        }

        return back()->with('success', 'PDF regenerated for '.$invoice->invoice_number);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $raw = trim($value);
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
