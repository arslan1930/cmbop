<?php

namespace App\Services\Billing;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Withdrawal;
use Illuminate\Support\Collection;

/**
 * Slim invoice payloads for admin Payments / Deposits / Withdrawals / Orders.
 */
class AdminInvoiceLinks
{
    /**
     * @return array{id: int, invoice_number: string, type: string, type_label: string, status: string, url: string}
     */
    public function summarize(Invoice $invoice): array
    {
        return [
            'id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'type' => (string) $invoice->type,
            'type_label' => $invoice->typeLabel(),
            'status' => (string) $invoice->status,
            'url' => route('admin.invoices.show', $invoice),
        ];
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return list<array{id: int, invoice_number: string, type: string, type_label: string, status: string, url: string}>
     */
    public function summarizeMany(Collection $invoices): array
    {
        return $invoices
            ->map(fn (Invoice $invoice) => $this->summarize($invoice))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, list<array{id: int, invoice_number: string, type: string, type_label: string, status: string, url: string}>>
     */
    public function forOrders(Collection $orders): Collection
    {
        $ids = $orders->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return Invoice::query()
            ->whereIn('order_id', $ids->all())
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (Invoice $invoice) => (int) $invoice->order_id)
            ->map(fn (Collection $docs) => $this->summarizeMany($docs));
    }

    /**
     * @param  Collection<int, DepositRequest>  $deposits
     * @return Collection<int, array{id: int, invoice_number: string, type: string, type_label: string, status: string, url: string}|null>
     */
    public function forDeposits(Collection $deposits): Collection
    {
        $userIds = $deposits->pluck('user_id')->filter()->unique()->values();
        $refs = $deposits->pluck('reference_code')->filter()->unique()->values();
        if ($userIds->isEmpty() || $refs->isEmpty()) {
            return $deposits->mapWithKeys(fn (DepositRequest $deposit) => [(int) $deposit->id => null]);
        }

        $receipts = Invoice::query()
            ->where('type', Invoice::TYPE_DEPOSIT_RECEIPT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->whereIn('user_id', $userIds->all())
            ->whereIn('reference_code', $refs->all())
            ->orderByDesc('id')
            ->get()
            ->keyBy(fn (Invoice $invoice) => $invoice->user_id.'|'.$invoice->reference_code);

        return $deposits->mapWithKeys(function (DepositRequest $deposit) use ($receipts) {
            $receipt = $receipts->get($deposit->user_id.'|'.$deposit->reference_code);

            return [(int) $deposit->id => $receipt ? $this->summarize($receipt) : null];
        });
    }

    /**
     * @param  Collection<int, Withdrawal>  $withdrawals
     * @return Collection<int, array{id: int, invoice_number: string, type: string, type_label: string, status: string, url: string}|null>
     */
    public function forWithdrawals(Collection $withdrawals): Collection
    {
        // WD-{id} is the statement key. Matching invoices.user_id would hide a
        // PAY doc after a publisher user_id correction and offer "Create statement".
        $refs = $withdrawals->map(fn (Withdrawal $withdrawal) => 'WD-'.$withdrawal->id)->unique()->values();
        if ($refs->isEmpty()) {
            return $withdrawals->mapWithKeys(fn (Withdrawal $withdrawal) => [(int) $withdrawal->id => null]);
        }

        $statements = Invoice::query()
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->whereIn('reference_code', $refs->all())
            ->orderByDesc('id')
            ->get()
            // unique() keeps the first row; after orderByDesc that is the newest.
            ->unique('reference_code')
            ->keyBy('reference_code');

        return $withdrawals->mapWithKeys(function (Withdrawal $withdrawal) use ($statements) {
            $statement = $statements->get('WD-'.$withdrawal->id);

            return [(int) $withdrawal->id => $statement ? $this->summarize($statement) : null];
        });
    }

    /**
     * @param  list<array{id: int, invoice_number: string, type: string, type_label: string, status: string, url: string}>  $documents
     * @return array{id: int, invoice_number: string, type: string, type_label: string, status: string, url: string}|null
     */
    public function primary(array $documents): ?array
    {
        foreach ($documents as $document) {
            if (($document['type'] ?? null) === Invoice::TYPE_TAX_INVOICE
                && ($document['status'] ?? null) !== Invoice::STATUS_CANCELLED) {
                return $document;
            }
        }

        foreach ($documents as $document) {
            if (($document['status'] ?? null) !== Invoice::STATUS_CANCELLED) {
                return $document;
            }
        }

        return $documents[0] ?? null;
    }
}
