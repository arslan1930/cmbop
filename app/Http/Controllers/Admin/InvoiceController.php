<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingEvent;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query()->with(['user:id,name,email', 'order:id,order_number']);

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $invoices = $query->latest('id')->paginate(25)->withQueryString();

        $stats = [
            'generated' => Invoice::count(),
            'downloaded' => (int) Invoice::sum('download_count'),
            'emailed' => (int) Invoice::sum('email_count'),
            'failures' => BillingEvent::where('event_type', 'invoice_generation_failed')->count(),
            'payment_failures' => Invoice::where('type', Invoice::TYPE_PAYMENT_FAILURE)->count(),
            'refunds' => Invoice::where('type', Invoice::TYPE_REFUND_RECEIPT)->count(),
        ];

        return view('admin.invoices.index', compact('invoices', 'stats'));
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['user', 'order.items', 'parentInvoice', 'events' => fn ($q) => $q->latest()->limit(30)]);

        return view('admin.invoices.show', compact('invoice'));
    }

    public function download(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        if (!$invoice->hasPdf() || !$invoice->pdfExists()) {
            $pdfs->generateAndStore($invoice);
            $invoice->refresh();
        }

        $billing->recordDownload($invoice);

        return $pdfs->download($invoice);
    }

    public function resend(Invoice $invoice, BillingDocumentService $billing)
    {
        $billing->resendInvoiceEmail($invoice);

        return back()->with('success', 'Invoice email resent to '.$invoice->customer_email);
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
        $invoice = $billing->generateManually($order, auth()->user());

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', 'Invoice '.$invoice->invoice_number.' generated.');
    }
}
