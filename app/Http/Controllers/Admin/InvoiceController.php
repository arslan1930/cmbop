<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingEvent;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\ActivityLogger;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Support\UserFacingError;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        $from = $this->parseDate($request->input('from'));
        $to = $this->parseDate($request->input('to'));
        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $invoices = new LengthAwarePaginator([], 0, 25);
        $invoices->withPath($request->url())->appends($request->query());
        $stats = $this->emptyInvoiceStats();

        if (Invoice::tableAvailable()) {
            try {
                $query = Invoice::query()->with(['user:id,name,email', 'order:id,order_number']);

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
                $status = is_string($request->input('status')) ? $request->input('status') : '';
                if ($status !== '' && in_array($status, $allowedStatuses, true)) {
                    $query->whereDisplayStatus($status);
                }

                $allowedTypes = [
                    Invoice::TYPE_TAX_INVOICE,
                    Invoice::TYPE_PAYMENT_RECEIPT,
                    Invoice::TYPE_REFUND_RECEIPT,
                    Invoice::TYPE_PAYMENT_FAILURE,
                    Invoice::TYPE_DEPOSIT_RECEIPT,
                    Invoice::TYPE_WITHDRAWAL_PAYOUT,
                ];
                $type = is_string($request->input('type')) ? $request->input('type') : '';
                if ($type !== '' && in_array($type, $allowedTypes, true)) {
                    $query->where('type', $type);
                }

                if ($from) {
                    $query->whereDate('invoice_date', '>=', $from->toDateString());
                }
                if ($to) {
                    $query->whereDate('invoice_date', '<=', $to->toDateString());
                }

                $invoices = $query->latest('invoice_date')->latest('id')->paginate(25)->withQueryString();
                $stats = $this->invoiceIndexStats();
            } catch (\Throwable $e) {
                Log::warning('Admin invoices index failed', [
                    'error' => $e->getMessage(),
                ]);
                $invoices = new LengthAwarePaginator([], 0, 25);
                $invoices->withPath($request->url())->appends($request->query());
            }
        }

        return view('admin.invoices.index', [
            'invoices' => $invoices,
            'stats' => $stats,
            'filterSearch' => $search,
            'filterFrom' => $from?->toDateString(),
            'filterTo' => $to?->toDateString(),
            'currencySymbol' => (string) config('billing.currency_symbol', '€'),
        ]);
    }

    public function show(Invoice $invoice)
    {
        $with = [
            'user:id,name,email',
            'order:id,order_number',
            'parentInvoice',
            'childInvoices',
            'cancelledBy:id,name,email',
        ];
        if (BillingEvent::tableAvailable()) {
            $with['events'] = fn ($q) => $q->latest()->limit(30);
        }

        try {
            $invoice->load($with);
        } catch (\Throwable $e) {
            Log::warning('Admin invoice show relations failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $invoice->relationLoaded('events')) {
            $invoice->setRelation('events', collect());
        }

        return view('admin.invoices.show', [
            'invoice' => $invoice,
            'relatedUrl' => $invoice->relatedAdminUrl(),
            'currencySymbol' => (string) config('billing.currency_symbol', '€'),
        ]);
    }

    public function viewPdf(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        try {
            if (! $invoice->hasPdf() || ! $invoice->pdfExists() || $invoice->storedPdfMayBeStale()) {
                $pdfs->generateAndStore($invoice);
                $invoice->refresh();
            }
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not generate the PDF.'));
        }

        try {
            $billing->recordAdminDownload($invoice, auth()->user());

            return $pdfs->stream($invoice);
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not open the PDF.'));
        }
    }

    public function download(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        try {
            if (! $invoice->hasPdf() || ! $invoice->pdfExists() || $invoice->storedPdfMayBeStale()) {
                $pdfs->generateAndStore($invoice);
                $invoice->refresh();
            }
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not generate the PDF.'));
        }

        try {
            $billing->recordAdminDownload($invoice, auth()->user());

            return $pdfs->download($invoice);
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not download the PDF.'));
        }
    }

    public function resend(Invoice $invoice, BillingDocumentService $billing)
    {
        $result = $billing->resendInvoiceEmail($invoice);

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        ActivityLogger::tryLog(
            'invoice.resent',
            (auth()->user()?->name ?? 'Admin').' resent invoice '.$invoice->invoice_number,
            $invoice,
            ['invoice_id' => $invoice->id],
            $invoice->invoice_number
        );

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

        if ($invoice->isCancelled()) {
            return back()->with('error', 'This invoice is already cancelled.');
        }

        try {
            $billing->cancelInvoice($invoice, auth()->user(), $data['reason'] ?? null);
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not cancel the invoice.'));
        }

        ActivityLogger::tryLog(
            'invoice.cancelled',
            (auth()->user()?->name ?? 'Admin').' cancelled invoice '.$invoice->invoice_number,
            $invoice,
            ['invoice_id' => $invoice->id, 'reason' => $data['reason'] ?? null],
            $invoice->invoice_number
        );

        return back()->with('success', 'Invoice cancelled. The PDF is retained for audit.');
    }

    public function generate(Request $request, BillingDocumentService $billing)
    {
        if ($denied = $this->invoicesUnavailableResponse()) {
            return $denied;
        }

        try {
            if (! Schema::hasTable('orders')) {
                return back()->with('error', 'Cannot generate an invoice because orders are unavailable on this database.');
            }
        } catch (\Throwable) {
            return back()->with('error', 'Cannot generate an invoice because orders are unavailable on this database.');
        }

        $data = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
        ]);

        $order = Order::with(['user', 'items'])->findOrFail($data['order_id']);

        $existingId = Invoice::query()
            ->where('order_id', $order->id)
            ->where('type', Invoice::TYPE_TAX_INVOICE)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->latest('id')
            ->value('id');

        try {
            $invoice = $billing->generateManually($order, auth()->user());
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not generate the invoice.'));
        }

        if (! $existingId || (int) $invoice->id !== (int) $existingId) {
            ActivityLogger::tryLog(
                'invoice.generated',
                (auth()->user()?->name ?? 'Admin').' generated invoice '.$invoice->invoice_number,
                $invoice,
                ['invoice_id' => $invoice->id, 'order_id' => $order->id],
                $invoice->invoice_number
            );
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
        if ($denied = $this->invoicesUnavailableResponse()) {
            return $denied;
        }

        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        try {
            $result = $billing->backfillMissingTaxInvoices((int) ($data['limit'] ?? 50));
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                UserFacingError::message($e, 'We could not backfill invoices. Please try again.')
            );
        }

        if ((int) ($result['created'] ?? 0) > 0 || (int) ($result['failed'] ?? 0) > 0) {
            ActivityLogger::tryLog(
                'invoice.backfill_run',
                ($request->user()?->name ?? 'Admin').' ran a tax-invoice backfill',
                null,
                [
                    'created' => (int) $result['created'],
                    'skipped' => (int) ($result['skipped'] ?? 0),
                    'failed' => (int) ($result['failed'] ?? 0),
                    'limit' => (int) ($data['limit'] ?? 50),
                ]
            );
        }

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
        if ($denied = $this->invoicesUnavailableResponse()) {
            return $denied;
        }

        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        try {
            $result = $billing->regenerateMissingPdfs((int) ($data['limit'] ?? 50));
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                UserFacingError::message($e, 'We could not regenerate missing PDFs. Please try again.')
            );
        }

        if ((int) ($result['regenerated'] ?? 0) > 0 || (int) ($result['failed'] ?? 0) > 0) {
            ActivityLogger::tryLog(
                'invoice.pdfs_regenerated',
                ($request->user()?->name ?? 'Admin').' ran a missing-invoice PDF regenerate',
                null,
                [
                    'regenerated' => (int) $result['regenerated'],
                    'failed' => (int) ($result['failed'] ?? 0),
                    'limit' => (int) ($data['limit'] ?? 50),
                ]
            );
        }

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

        ActivityLogger::tryLog(
            'invoice.pdf_regenerated',
            (auth()->user()?->name ?? 'Admin').' regenerated the PDF for invoice '.$invoice->invoice_number,
            $invoice,
            ['invoice_id' => $invoice->id],
            $invoice->invoice_number
        );

        return back()->with('success', 'PDF regenerated for '.$invoice->invoice_number);
    }

    /**
     * @return array<string, int>
     */
    private function emptyInvoiceStats(): array
    {
        return [
            'documents' => 0,
            'tax_invoices' => 0,
            'downloaded' => 0,
            'emailed' => 0,
            'failures' => 0,
            'payment_failures' => 0,
            'refunds' => 0,
            'deposits' => 0,
            'payouts' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function invoiceIndexStats(): array
    {
        $stats = $this->emptyInvoiceStats();
        $stats['documents'] = Invoice::count();
        $stats['tax_invoices'] = Invoice::where('type', Invoice::TYPE_TAX_INVOICE)->count();
        $stats['downloaded'] = (int) Invoice::sum('download_count');
        $stats['emailed'] = (int) Invoice::sum('email_count');
        $stats['payment_failures'] = Invoice::where('type', Invoice::TYPE_PAYMENT_FAILURE)->count();
        $stats['refunds'] = Invoice::where('type', Invoice::TYPE_REFUND_RECEIPT)->count();
        $stats['deposits'] = Invoice::where('type', Invoice::TYPE_DEPOSIT_RECEIPT)->count();
        $stats['payouts'] = Invoice::where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)->count();
        if (BillingEvent::tableAvailable()) {
            $stats['failures'] = BillingEvent::where('event_type', 'invoice_generation_failed')->count();
        }

        return $stats;
    }

    private function invoicesUnavailableResponse()
    {
        if (Invoice::tableAvailable()) {
            return null;
        }

        return back()->with('error', 'Invoices are temporarily unavailable. Please try again after migrations are applied.');
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
