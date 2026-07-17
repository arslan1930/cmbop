<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InvoicePdfGenerator
{
    public function generateAndStore(Invoice $invoice): Invoice
    {
        $html = view('billing.pdf.invoice', [
            'invoice' => $invoice,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => config('billing.currency_symbol', '€'),
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        $binary = $pdf->output();

        $disk = (string) config('billing.storage.disk', 'local');
        $directory = trim((string) config('billing.storage.directory', 'invoices'), '/');
        $filename = sprintf(
            '%s/%s/%s-%s.pdf',
            $directory,
            now()->format('Y/m'),
            Str::slug($invoice->invoice_number),
            Str::lower(Str::random(8))
        );

        Storage::disk($disk)->put($filename, $binary);

        $invoice->update([
            'pdf_disk' => $disk,
            'pdf_path' => $filename,
        ]);

        return $invoice->fresh();
    }

    public function stream(Invoice $invoice)
    {
        if ($invoice->pdfExists()) {
            return Storage::disk($invoice->pdf_disk)->response(
                $invoice->pdf_path,
                $invoice->invoice_number.'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        $html = view('billing.pdf.invoice', [
            'invoice' => $invoice,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => config('billing.currency_symbol', '€'),
        ])->render();

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->stream($invoice->invoice_number.'.pdf');
    }

    public function download(Invoice $invoice)
    {
        if ($invoice->pdfExists()) {
            return Storage::disk($invoice->pdf_disk)->download(
                $invoice->pdf_path,
                $invoice->invoice_number.'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        $html = view('billing.pdf.invoice', [
            'invoice' => $invoice,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => config('billing.currency_symbol', '€'),
        ])->render();

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->download($invoice->invoice_number.'.pdf');
    }

    public function absolutePath(Invoice $invoice): ?string
    {
        if (!$invoice->pdfExists()) {
            return null;
        }

        return Storage::disk($invoice->pdf_disk)->path($invoice->pdf_path);
    }
}
