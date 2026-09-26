<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Advertiser\BillingController;
use App\Http\Controllers\Controller;
use App\Models\BillingEvent;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\ActivityLogger;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Services\Billing\InvoiceRepairQueue;
use App\Support\UserFacingError;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class InvoiceController extends Controller
{
    private const EXPORT_LIMIT = 5000;

    public function __construct(private InvoiceRepairQueue $repairQueue) {}

    public function index(Request $request)
    {
        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        $dateError = null;
        $invoices = new LengthAwarePaginator([], 0, 25);
        $invoices->withPath($request->url())->appends($request->query());
        $missingOrders = new LengthAwarePaginator([], 0, 25);
        $missingOrders->withPath($request->url())->appends($request->query());
        $stats = $this->emptyInvoiceStats();
        $currencyTotals = [];
        $matchCount = 0;
        $exportLimited = false;
        $missingTaxCount = 0;
        $missingPdfCount = 0;
        $showMissingOrders = ! $request->boolean('finance') && $request->input('queue') === 'missing';

        if (Invoice::tableAvailable()) {
            try {
                $filtered = $this->invoicesQuery($request, $dateError);
                $matchCount = (clone $filtered)->count();
                $currencyTotals = $this->invoiceCurrencyTotals($filtered);
                $exportLimited = $matchCount > self::EXPORT_LIMIT;
                $invoices = $this->applyInvoiceSort(clone $filtered, $request)->paginate(25)->withQueryString();
                $stats = $this->invoiceIndexStats();
                $missingTaxCount = $this->repairQueue->missingTaxInvoiceCount();
                $missingPdfCount = $this->repairQueue->missingPdfPathCount();
                if ($showMissingOrders && Schema::hasTable('orders')) {
                    $missingQuery = $this->repairQueue->missingTaxInvoiceOrders();
                    $this->repairQueue->constrainMissingOrderSearch($missingQuery, $search);
                    $missingOrders = $missingQuery->paginate(25)->withQueryString();
                }
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
            'missingOrders' => $missingOrders,
            'showMissingOrders' => $showMissingOrders,
            'stats' => $stats,
            'filterSearch' => $request->boolean('finance') ? '' : $search,
            'filterFrom' => $this->rawInvoiceDay($request, 'from', 'date_from'),
            'filterTo' => $this->rawInvoiceDay($request, 'to', 'date_to'),
            'currencySymbol' => (string) config('billing.currency_symbol', '€'),
            'currencyTotals' => $currencyTotals,
            'matchCount' => $matchCount,
            'exportLimited' => $exportLimited,
            'dateError' => $dateError,
            'missingTaxCount' => $missingTaxCount,
            'missingPdfCount' => $missingPdfCount,
            'financeClock' => $request->boolean('finance'),
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
        $eventTotal = 0;
        if (BillingEvent::tableAvailable()) {
            try {
                $eventTotal = $invoice->events()->count();
            } catch (\Throwable) {
                $eventTotal = 0;
            }
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
            'eventsTruncated' => $invoice->events->isNotEmpty() && $eventTotal > $invoice->events->count(),
        ]);
    }

    public function viewPdf(Invoice $invoice, BillingDocumentService $billing)
    {
        try {
            $invoice->loadMissing(['order.items', 'user']);
            $billing->recordAdminDownload($invoice, auth()->user());

            return response()->view(
                'advertiser.invoice',
                app(BillingController::class)->wiseStyleInvoiceData($invoice)
            );
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not open the invoice.'));
        }
    }

    public function download(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        try {
            $pdfs->ensureCustomerPdf($invoice);
            $invoice->refresh();
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

        $ref = trim((string) ($request->input('order_ref') ?? $request->input('order_id') ?? ''));
        if ($ref === '') {
            return back()->with('error', 'Enter an order number.');
        }

        $order = Order::with(['user', 'items'])->where('order_number', $ref)->first();
        if (! $order && ctype_digit($ref) && (string) (int) $ref === $ref) {
            $order = Order::with(['user', 'items'])->find((int) $ref);
        }

        if (! $order) {
            return back()->with('error', 'No order matches that number.');
        }

        if ($order->payment_status !== 'paid' && ! $request->boolean('confirm')) {
            return back()
                ->with('warning', 'This order is not paid. Confirm to issue a tax invoice anyway.')
                ->with('confirm_unpaid_order', $ref);
        }

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
                'Backfill complete: %d tax invoices created, %d skipped, %d failed. %d paid orders still have no tax invoice. Payment receipts are not backfilled.',
                $result['created'],
                $result['skipped'],
                $result['failed'],
                $this->repairQueue->missingTaxInvoiceCount()
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
                'PDF regenerate complete: %d regenerated, %d failed. %d missing files remain.',
                $result['regenerated'],
                $result['failed'],
                (int) ($result['remaining'] ?? 0)
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

    public function export(Request $request)
    {
        $dateError = null;
        $rows = collect();
        if (Invoice::tableAvailable()) {
            try {
                $rows = $this->applyInvoiceSort($this->invoicesQuery($request, $dateError), $request)
                    ->limit(self::EXPORT_LIMIT)
                    ->get();
            } catch (\Throwable $e) {
                Log::warning('Admin invoice export failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'invoice_number',
                'type',
                'status',
                'currency',
                'total_amount',
                'payment_method',
                'transaction_id',
                'order_number',
                'reference_code',
                'invoice_date',
                'customer_name',
                'customer_email',
            ]);
            foreach ($rows as $invoice) {
                fputcsv($out, [
                    $this->csvCell($invoice->invoice_number),
                    $this->csvCell($invoice->type),
                    $this->csvCell($invoice->status),
                    $this->csvCell($invoice->currency),
                    number_format((float) $invoice->total_amount, 2, '.', ''),
                    $this->csvCell($invoice->payment_method),
                    $this->csvCell($invoice->transaction_id),
                    $this->csvCell($invoice->order_number),
                    $this->csvCell($invoice->reference_code),
                    optional($invoice->invoice_date)->toDateTimeString(),
                    $this->csvCell($invoice->customer_name),
                    $this->csvCell($invoice->customer_email),
                ]);
            }
            fclose($out);
        }, 'invoices-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Builder<Invoice>
     */
    private function invoicesQuery(Request $request, ?string &$dateError = null)
    {
        $query = Invoice::query()->with(['user:id,name,email', 'order:id,order_number']);
        [$from, $to, $dateError] = $this->invoiceDateWindow($request);

        if ($request->boolean('finance')) {
            if ($dateError) {
                $query->whereRaw('0 = 1');

                return $query;
            }
            $this->applyInvoiceDates($query, $from, $to);

            return $query;
        }

        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        $needle = str_replace(['\\', '%', '_'], '', $search);
        if ($search !== '' && $needle === '') {
            $query->whereRaw('0 = 1');
        } elseif ($search !== '') {
            $like = like_contains($search);
            $query->where(function ($q) use ($like, $search) {
                foreach (['invoice_number', 'order_number', 'reference_code', 'customer_name', 'customer_email', 'transaction_id'] as $column) {
                    $q->orWhereRaw($column.' LIKE ? ESCAPE ?', [$like, '\\']);
                }
                if (ctype_digit($search) && (string) (int) $search === $search) {
                    $q->orWhere('id', (int) $search);
                }
                $q->orWhereHas('user', function ($user) use ($like) {
                    $user->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                        ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
                    if (Schema::hasColumn('users', 'company_name')) {
                        $user->orWhereRaw('company_name LIKE ? ESCAPE ?', [$like, '\\']);
                    }
                });
            });
        }

        $status = is_string($request->input('status')) ? $request->input('status') : '';
        if ($status !== '' && in_array($status, [
            Invoice::STATUS_PAID,
            Invoice::STATUS_ISSUED,
            Invoice::STATUS_PENDING,
            Invoice::STATUS_FAILED,
            Invoice::STATUS_REFUNDED,
            Invoice::STATUS_CANCELLED,
        ], true)) {
            $query->where('status', $status);
        }

        $type = is_string($request->input('type')) ? $request->input('type') : '';
        if ($type !== '' && in_array($type, [
            Invoice::TYPE_TAX_INVOICE,
            Invoice::TYPE_PAYMENT_RECEIPT,
            Invoice::TYPE_REFUND_RECEIPT,
            Invoice::TYPE_PAYMENT_FAILURE,
            Invoice::TYPE_DEPOSIT_RECEIPT,
            Invoice::TYPE_WITHDRAWAL_PAYOUT,
        ], true)) {
            $query->where('type', $type);
        }

        if ($request->input('pdf') === 'missing' && Schema::hasColumn('invoices', 'pdf_path')) {
            $query->where(function ($q) {
                $q->whereNull('pdf_path')->orWhere('pdf_path', '');
            });
        }

        if (! $dateError) {
            $this->applyInvoiceDates($query, $from, $to);
        }

        return $query;
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    private function applyInvoiceDates($query, ?Carbon $from, ?Carbon $to): void
    {
        if ($from) {
            $query->whereDate('invoice_date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate('invoice_date', '<=', $to->toDateString());
        }
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon, 2: ?string}
     */
    private function invoiceDateWindow(Request $request): array
    {
        $fromRaw = $this->rawInvoiceDay($request, 'from', 'date_from');
        $toRaw = $this->rawInvoiceDay($request, 'to', 'date_to');
        $fromOk = $fromRaw === '' || $this->parseDate($fromRaw) !== null;
        $toOk = $toRaw === '' || $this->parseDate($toRaw) !== null;
        if (! $fromOk || ! $toOk) {
            return [null, null, 'Enter real dates.'];
        }
        if ($fromRaw !== '' && $toRaw !== '' && $toRaw < $fromRaw) {
            return [null, null, 'The to date must be on or after the from date.'];
        }

        return [$this->parseDate($fromRaw), $this->parseDate($toRaw), null];
    }

    private function rawInvoiceDay(Request $request, string $primary, string $fallback): string
    {
        $value = $request->input($primary);
        if (! is_string($value) || trim($value) === '') {
            $value = $request->input($fallback);
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function applyInvoiceSort($query, Request $request)
    {
        $sort = is_string($request->input('sort')) ? $request->input('sort') : '';

        return match ($sort) {
            'oldest' => $query->orderBy('invoice_date')->orderBy('id'),
            'amount' => $query->orderByDesc('total_amount')->orderByDesc('id'),
            default => $query->orderByDesc('invoice_date')->orderByDesc('id'),
        };
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return array<string, float>
     */
    private function invoiceCurrencyTotals($query): array
    {
        if (! Schema::hasColumn('invoices', 'currency')) {
            return ['EUR' => round((float) (clone $query)->sum('total_amount'), 2)];
        }

        $totals = [];
        $inner = (clone $query)->setEagerLoads([])->reorder();
        $inner->getQuery()->columns = null;
        $inner->selectRaw("UPPER(COALESCE(NULLIF(currency, ''), 'EUR')) as code, total_amount");
        $rows = DB::query()
            ->fromSub($inner, 'invoice_currency_rows')
            ->selectRaw('code, SUM(total_amount) as total')
            ->groupBy('code')
            ->get();
        foreach ($rows as $row) {
            $code = strtoupper(trim((string) $row->code));
            if ($code !== '') {
                $totals[$code] = round((float) $row->total, 2);
            }
        }
        ksort($totals);

        return $totals;
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
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
