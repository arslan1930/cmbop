<?php

namespace App\Services\Billing;

use App\Models\InvoiceSequence;
use Illuminate\Support\Facades\DB;

class InvoiceNumberGenerator
{
    /**
     * Allocate the next unique sequential invoice number: INV-2026-000001
     */
    public function next(?int $year = null): string
    {
        $year = $year ?: (int) now()->format('Y');
        $prefix = (string) config('billing.invoice_number.prefix', 'INV');
        $pad = (int) config('billing.invoice_number.pad', 6);

        return DB::transaction(function () use ($year, $prefix, $pad) {
            $sequence = InvoiceSequence::query()
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                $sequence = InvoiceSequence::create([
                    'year' => $year,
                    'last_number' => 0,
                ]);
                $sequence = InvoiceSequence::query()
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();
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
}
