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
        $binary = $this->renderPdf($invoice)->output();

        $disk = (string) config('billing.storage.disk', 'local');
        $directory = trim((string) config('billing.storage.directory', 'invoices'), '/');
        $filename = (string) ($invoice->pdf_path ?? '');
        $reuse = $filename !== ''
            && (string) $invoice->pdf_disk === $disk
            && Storage::disk($disk)->exists($filename);

        if (! $reuse) {
            $filename = sprintf(
                '%s/%s/%s-%s.pdf',
                $directory,
                now()->format('Y/m'),
                Str::slug($invoice->invoice_number),
                Str::lower(Str::random(8))
            );
        }

        Storage::disk($disk)->put($filename, $binary);

        $invoice->update([
            'pdf_disk' => $disk,
            'pdf_path' => $filename,
        ]);

        return $invoice->fresh();
    }

    public function stream(Invoice $invoice)
    {
        $invoice = $this->ensureCustomerPdf($invoice);

        if ($invoice->pdfExists()) {
            return Storage::disk($invoice->pdfStorageDisk())->response(
                $invoice->pdf_path,
                $invoice->invoice_number.'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        return $this->renderPdf($invoice)->stream($invoice->invoice_number.'.pdf');
    }

    public function download(Invoice $invoice)
    {
        $invoice = $this->ensureCustomerPdf($invoice);

        if ($invoice->pdfExists()) {
            return Storage::disk($invoice->pdfStorageDisk())->download(
                $invoice->pdf_path,
                $invoice->invoice_number.'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        return $this->renderPdf($invoice)->download($invoice->invoice_number.'.pdf');
    }

    /**
     * Rebuild a stored PDF that still prints leftover APP_URL (localhost)
     * or used a core font so completed invoices show as garbled glyphs.
     */
    public function ensureCustomerPdf(Invoice $invoice): Invoice
    {
        if ($invoice->pdfExists()
            && ! $this->storedPdfHasLeftoverHost($invoice)
            && $this->storedPdfEmbedsUnicodeFont($invoice)) {
            return $invoice;
        }

        return $this->generateAndStore($invoice);
    }

    private function storedPdfHasLeftoverHost(Invoice $invoice): bool
    {
        try {
            $binary = (string) Storage::disk($invoice->pdfStorageDisk())->get($invoice->pdf_path);
        } catch (\Throwable) {
            return true;
        }

        return str_contains($binary, 'localhost')
            || str_contains($binary, '127.0.0.1')
            || str_contains($binary, '://[::1]');
    }

    private function storedPdfEmbedsUnicodeFont(Invoice $invoice): bool
    {
        try {
            $binary = (string) Storage::disk($invoice->pdfStorageDisk())->get($invoice->pdf_path);
        } catch (\Throwable) {
            return false;
        }

        return str_contains($binary, 'DejaVu');
    }

    public function absolutePath(Invoice $invoice): ?string
    {
        if (! $invoice->pdfExists()) {
            return null;
        }

        return Storage::disk($invoice->pdfStorageDisk())->path($invoice->pdf_path);
    }

    /**
     * Dompdf's CPDF adapter needs PHP GD to embed PNG logos. Hostinger /
     * this VM can lack gd — still produce the invoice, just without the raster.
     */
    private function renderPdf(Invoice $invoice)
    {
        $includeLogo = extension_loaded('gd');

        try {
            return $this->makePdf($invoice, $includeLogo);
        } catch (\Throwable $e) {
            if (! $includeLogo || ! $this->isMissingGdException($e)) {
                throw $e;
            }

            return $this->makePdf($invoice, false);
        }
    }

    private function makePdf(Invoice $invoice, bool $includeLogo)
    {
        $previousConvert = config('dompdf.convert_entities');
        config(['dompdf.convert_entities' => false]);

        try {
            $html = view('billing.pdf.invoice', [
                'invoice' => $invoice,
                'company' => function_exists('billing_company_for_documents')
                    ? billing_company_for_documents()
                    : config('billing.company'),
                'colors' => config('billing.colors'),
                'currencySymbol' => config('billing.currency_symbol', '€'),
                'includeLogo' => $includeLogo,
            ])->render();

            $fontDir = base_path('vendor/dompdf/dompdf/lib/fonts');
            $fontCache = storage_path('fonts');
            if (! is_dir($fontCache)) {
                mkdir($fontCache, 0755, true);
            }

            $pdf = Pdf::loadHTML($html, 'UTF-8')
                ->setPaper('a4', 'portrait')
                ->setOption([
                    'defaultFont' => 'DejaVu Sans',
                    'isFontSubsettingEnabled' => false,
                    'fontDir' => $fontDir,
                    'fontCache' => $fontCache,
                ]);
            // Force rasterization now so a missing-GD throw happens here,
            // not inside stream()/download() after headers may have started.
            $pdf->output();

            return $pdf;
        } finally {
            config(['dompdf.convert_entities' => $previousConvert]);
        }
    }

    private function isMissingGdException(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'GD extension')
            || str_contains($e->getMessage(), 'GD is required');
    }
}
