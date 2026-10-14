<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceSequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InvoiceNumberGenerator
{
    /**
     * Allocate the next unique sequential tax invoice number: INV-2026-000001
     */
    public function next(?int $year = null): string
    {
        return $this->nextInSeries(
            (string) config('billing.invoice_number.prefix', 'INV'),
            (int) config('billing.invoice_number.pad', 6),
            $year
        );
    }

    /**
     * Allocate the next deposit (wallet top-up) receipt number: RCT-2026-000001
     */
    public function nextReceipt(?int $year = null): string
    {
        return $this->nextInSeries(
            (string) config('billing.receipt_number.prefix', 'RCT'),
            (int) config('billing.receipt_number.pad', 6),
            $year
        );
    }

    /**
     * Payment receipt / payment-failure report number: RCPT-2026-000001
     * Kept out of the INV tax-invoice series.
     */
    public function nextPaymentReceipt(?int $year = null): string
    {
        return $this->nextInSeries(
            (string) config('billing.payment_receipt_number.prefix', 'RCPT'),
            (int) config('billing.payment_receipt_number.pad', 6),
            $year
        );
    }

    /**
     * Credit note / refund receipt number: CN-2026-000001
     */
    public function nextCreditNote(?int $year = null): string
    {
        return $this->nextInSeries(
            (string) config('billing.credit_note_number.prefix', 'CN'),
            (int) config('billing.credit_note_number.pad', 6),
            $year
        );
    }

    /**
     * Withdrawal payout statement number: PAY-2026-000001
     */
    public function nextPayoutStatement(?int $year = null): string
    {
        return $this->nextInSeries(
            (string) config('billing.payout_statement_number.prefix', 'PAY'),
            (int) config('billing.payout_statement_number.pad', 6),
            $year
        );
    }

    /**
     * Pick the correct series for an invoice document type.
     */
    public function nextForType(string $type, ?int $year = null): string
    {
        return match ($type) {
            Invoice::TYPE_DEPOSIT_RECEIPT => $this->nextReceipt($year),
            Invoice::TYPE_PAYMENT_RECEIPT,
            Invoice::TYPE_PAYMENT_FAILURE => $this->nextPaymentReceipt($year),
            Invoice::TYPE_REFUND_RECEIPT => $this->nextCreditNote($year),
            Invoice::TYPE_WITHDRAWAL_PAYOUT => $this->nextPayoutStatement($year),
            default => $this->next($year),
        };
    }

    /**
     * Calendar year for a paid_at / invoice_date. Existing numbers are never rewritten.
     */
    public function yearFromDate(mixed $date): ?int
    {
        if ($date instanceof \DateTimeInterface) {
            $year = (int) $date->format('Y');

            return ($year >= 2000 && $year <= 2100) ? $year : null;
        }

        if (is_string($date) && trim($date) !== '') {
            try {
                return $this->yearFromDate(Carbon::parse($date));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Each series keeps its own gap-free counter per calendar year.
     */
    private function nextInSeries(string $prefix, int $pad, ?int $year = null): string
    {
        $year = $year ?: (int) now()->format('Y');

        if (! $this->sequencesTableAvailable()) {
            throw new \RuntimeException('Invoice number sequences are unavailable. Apply the billing migrations.');
        }

        return DB::transaction(function () use ($year, $prefix, $pad) {
            $sequence = $this->lockSequence($prefix, $year);

            if (! $sequence) {
                $row = [
                    'year' => $year,
                    'last_number' => 0,
                ];
                if ($this->sequencesHaveSeries()) {
                    $row['series'] = $prefix;
                }
                InvoiceSequence::create($row);
                $sequence = $this->lockSequence($prefix, $year);
            }

            $sequence->last_number = ((int) $sequence->last_number) + 1;
            $sequence->save();

            return sprintf(
                '%s-%d-%s',
                $prefix,
                $year,
                str_pad((string) $sequence->last_number, $pad, '0', STR_PAD_LEFT)
            );
        });
    }

    private function lockSequence(string $prefix, int $year): ?InvoiceSequence
    {
        $query = InvoiceSequence::query()->where('year', $year);
        if ($this->sequencesHaveSeries()) {
            $query->where('series', $prefix);
        }

        return $query->lockForUpdate()->first();
    }

    private function sequencesTableAvailable(): bool
    {
        try {
            return Schema::hasTable('invoice_sequences');
        } catch (\Throwable) {
            return false;
        }
    }

    private function sequencesHaveSeries(): bool
    {
        try {
            return Schema::hasColumn('invoice_sequences', 'series');
        } catch (\Throwable) {
            return false;
        }
    }
}
