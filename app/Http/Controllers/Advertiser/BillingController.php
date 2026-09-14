<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Invoice::query()
                ->where('user_id', auth()->id())
                ->whereIn('type', [
                    Invoice::TYPE_TAX_INVOICE,
                    Invoice::TYPE_PAYMENT_RECEIPT,
                    Invoice::TYPE_REFUND_RECEIPT,
                    Invoice::TYPE_PAYMENT_FAILURE,
                    Invoice::TYPE_DEPOSIT_RECEIPT,
                ])
                ->with('order:id,order_number,reference_code,payment_status');

            $search = search_text($request->input('search'));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$search}%")
                        ->orWhere('reference_code', 'like', "%{$search}%")
                        ->orWhere('transaction_id', 'like', "%{$search}%");
                });
            }

            if ($request->filled('status')) {
                $query->whereDisplayStatus((string) $request->status);
            }

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            $from = search_text($request->input('from'));
            if ($from !== '') {
                $query->whereDate('invoice_date', '>=', $from);
            }

            $to = search_text($request->input('to'));
            if ($to !== '') {
                $query->whereDate('invoice_date', '<=', $to);
            }

            $invoices = $query->latest('invoice_date')->latest('id')->paginate(20)->withQueryString();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Unable to load invoices. Please refresh and try again.')
            );
            $invoices = new LengthAwarePaginator([], 0, 20, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
        }

        return view('advertiser.billing.index', compact('invoices'));
    }

    public function show(Invoice $invoice)
    {
        $this->authorizeOwner($invoice);
        try {
            $invoice->load(['order.items', 'parentInvoice', 'childInvoices']);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('advertiser.billing.index')
                ->with('error', UserFacingError::message($e, 'Unable to load that invoice.'));
        }

        return view('advertiser.billing.show', compact('invoice'));
    }

    public function download(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        $this->authorizeOwner($invoice);

        if ($invoice->isCancelled() && $invoice->type === Invoice::TYPE_TAX_INVOICE) {
            abort(403, 'This invoice has been cancelled.');
        }

        try {
            if (! $invoice->hasPdf() || ! $invoice->pdfExists() || $invoice->storedPdfMayBeStale()) {
                $pdfs->generateAndStore($invoice);
                $invoice->refresh();
            }

            $billing->recordDownload($invoice);

            return $pdfs->download($invoice);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('advertiser.billing.show', $invoice)
                ->with('error', UserFacingError::message($e, 'Unable to download that invoice.'));
        }
    }

    public function viewPdf(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        $this->authorizeOwner($invoice);

        if ($invoice->isCancelled() && $invoice->type === Invoice::TYPE_TAX_INVOICE) {
            abort(403, 'This invoice has been cancelled.');
        }

        try {
            if (! $invoice->hasPdf() || ! $invoice->pdfExists() || $invoice->storedPdfMayBeStale()) {
                $pdfs->generateAndStore($invoice);
                $invoice->refresh();
            }

            $billing->recordDownload($invoice);

            return $pdfs->stream($invoice);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('advertiser.billing.show', $invoice)
                ->with('error', UserFacingError::message($e, 'Unable to open that invoice.'));
        }
    }

    private function authorizeOwner(Invoice $invoice): void
    {
        if ((int) $invoice->user_id !== (int) auth()->id() && ! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }
}
