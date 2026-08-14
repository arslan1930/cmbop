<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query()
            ->where('user_id', auth()->id())
            ->whereIn('type', [
                Invoice::TYPE_TAX_INVOICE,
                Invoice::TYPE_PAYMENT_RECEIPT,
                Invoice::TYPE_REFUND_RECEIPT,
                Invoice::TYPE_PAYMENT_FAILURE,
                Invoice::TYPE_DEPOSIT_RECEIPT,
            ])
            ->with('order:id,order_number,reference_code');

        if ($request->filled('search')) {
            $search = trim(scalar_text($request->search));
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%");
            });
        }

        $status = scalar_text($request->status);
        if ($status !== '') {
            $query->where('status', $status);
        }

        $type = scalar_text($request->type);
        if ($type !== '') {
            $query->where('type', $type);
        }

        $from = scalar_text($request->from);
        if ($from !== '') {
            $query->whereDate('invoice_date', '>=', $from);
        }

        $to = scalar_text($request->to);
        if ($to !== '') {
            $query->whereDate('invoice_date', '<=', $to);
        }

        $invoices = $query->latest('invoice_date')->latest('id')->paginate(20)->withQueryString();

        return view('advertiser.billing.index', compact('invoices'));
    }

    public function show(Invoice $invoice)
    {
        $this->authorizeOwner($invoice);
        $invoice->load(['order.items', 'parentInvoice']);

        return view('advertiser.billing.show', compact('invoice'));
    }

    public function download(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        $this->authorizeOwner($invoice);

        if ($invoice->isCancelled() && $invoice->type === Invoice::TYPE_TAX_INVOICE) {
            abort(403, 'This invoice has been cancelled.');
        }

        if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
            $pdfs->generateAndStore($invoice);
            $invoice->refresh();
        }

        $billing->recordDownload($invoice);

        return $pdfs->download($invoice);
    }

    public function viewPdf(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        $this->authorizeOwner($invoice);

        if ($invoice->isCancelled() && $invoice->type === Invoice::TYPE_TAX_INVOICE) {
            abort(403, 'This invoice has been cancelled.');
        }

        if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
            $pdfs->generateAndStore($invoice);
            $invoice->refresh();
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
}
