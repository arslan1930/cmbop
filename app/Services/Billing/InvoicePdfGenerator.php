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
        $binary = $this->stampCurrentSeller($this->renderPdfBinary($invoice));

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

        return $this->pdfResponse($invoice, 'inline');
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

        return $this->pdfResponse($invoice, 'attachment');
    }

    /**
     * A one-off PDF for a payment page that has no stored billing document yet.
     * The model is not saved. output() runs once so the character map stays intact.
     */
    public function attachment(Invoice $invoice, string $filename)
    {
        $binary = $this->renderPdfBinary($invoice);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $filename) ?: 'invoice.pdf';
        if (! str_ends_with(strtolower($safe), '.pdf')) {
            $safe .= '.pdf';
        }

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$safe.'"',
        ]);
    }

    /**
     * Rebuild a stored PDF that still prints leftover APP_URL (localhost),
     * used a core font so completed invoices show as garbled glyphs, or
     * was saved before the current seller and pay-in account.
     */
    public function ensureCustomerPdf(Invoice $invoice): Invoice
    {
        if ($invoice->pdfExists()
            && ! $this->storedPdfHasLeftoverHost($invoice)
            && $this->storedPdfEmbedsUnicodeFont($invoice)
            && $this->storedPdfMatchesCurrentSeller($invoice)) {
            return $invoice;
        }

        return $this->generateAndStore($invoice);
    }

    private function currentSellerMark(): string
    {
        $legal = preg_replace('/\s+/', ' ', trim((string) config('billing.company.legal_name'))) ?: '';
        $iban = preg_replace('/\s+/', '', (string) config('billing.deposit_payment.iban')) ?: '';

        return '% CMBOP-SELLER '.$legal.' '.$iban;
    }

    private function stampCurrentSeller(string $binary): string
    {
        $mark = $this->currentSellerMark();
        if ($mark === '% CMBOP-SELLER  ' || str_contains($binary, $mark)) {
            return $binary;
        }

        return $binary."\n".$mark."\n";
    }

    private function storedPdfMatchesCurrentSeller(Invoice $invoice): bool
    {
        try {
            $binary = (string) Storage::disk($invoice->pdfStorageDisk())->get($invoice->pdf_path);
        } catch (\Throwable) {
            return false;
        }

        return str_contains($binary, $this->currentSellerMark());
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

        return str_contains($binary, 'DejaVu')
            && str_contains($binary, '/FontFile2')
            && $this->pdfFlateStreamsDecode($binary);
    }

    /**
     * A second Dompdf output() re-decodes the CIDToGID map and stores it as
     * FlateDecode. The file still says DejaVu, but the viewer draws the wrong glyphs.
     */
    private function pdfFlateStreamsDecode(string $binary): bool
    {
        $offset = 0;
        $marker = '/Filter /FlateDecode';

        while (($filter = strpos($binary, $marker, $offset)) !== false) {
            $streamAt = strpos($binary, 'stream', $filter);
            $end = $streamAt === false ? false : strpos($binary, "\nendstream", $streamAt);
            if ($streamAt === false || $end === false || $end <= $streamAt) {
                return false;
            }

            $start = $streamAt + strlen('stream');
            if (($binary[$start] ?? '') === "\r") {
                $start++;
            }
            if (($binary[$start] ?? '') === "\n") {
                $start++;
            } else {
                return false;
            }

            $data = substr($binary, $start, $end - $start);
            if ($data === '' || ord($data[0]) !== 0x78 || @gzuncompress($data) === false) {
                return false;
            }

            $offset = $end + strlen("\nendstream");
        }

        return true;
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
    private function pdfResponse(Invoice $invoice, string $disposition)
    {
        $binary = $this->renderPdfBinary($invoice);
        $filename = $invoice->invoice_number.'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
        ]);
    }

    private function renderPdfBinary(Invoice $invoice): string
    {
        $includeLogo = extension_loaded('gd');

        try {
            return $this->makePdfBinary($invoice, $includeLogo);
        } catch (\Throwable $e) {
            if (! $includeLogo || ! $this->isMissingGdException($e)) {
                throw $e;
            }

            return $this->makePdfBinary($invoice, false);
        }
    }

    private function makePdfBinary(Invoice $invoice, bool $includeLogo): string
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

            // Call output() once. Dompdf decodes the CIDToGID map in place;
            // a second output() on this instance stores a broken map and the
            // downloaded PDF draws the wrong glyphs.
            return $pdf->output();
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
