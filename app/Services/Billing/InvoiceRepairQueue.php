<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Paid orders with no live tax invoice, and invoices with an empty PDF path.
 * The invoice page and the admin dashboard share these queries.
 */
class InvoiceRepairQueue
{
    public function missingTaxInvoiceCount(): int
    {
        if (! Schema::hasTable('orders') || ! Invoice::tableAvailable()) {
            return 0;
        }

        try {
            return $this->missingTaxInvoiceOrders()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function missingPdfPathCount(): int
    {
        if (! Schema::hasColumn('invoices', 'pdf_path')) {
            return 0;
        }

        try {
            return $this->missingPdfInvoices()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Same search the invoice list uses, applied to the missing-tax order rows.
     *
     * @param  Builder<Order>  $query
     */
    public function constrainMissingOrderSearch(Builder $query, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $needle = str_replace(['\\', '%', '_'], '', $search);
        if ($needle === '') {
            $query->whereRaw('0 = 1');

            return;
        }

        $like = like_contains($search);
        $query->where(function ($q) use ($like, $search) {
            $q->whereRaw('order_number LIKE ? ESCAPE ?', [$like, '\\']);
            if (ctype_digit($search) && (string) (int) $search === $search) {
                $q->orWhere($q->getModel()->getQualifiedKeyName(), (int) $search);
            }
            $q->orWhereHas('user', function ($user) use ($like) {
                $user->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
            });
        });
    }

    /**
     * Newest paid date first, matching the invoice repair list.
     *
     * @return Builder<Order>
     */
    public function missingTaxInvoiceOrders(): Builder
    {
        $query = $this->missingTaxInvoiceBase()->with('user:id,name,email');
        if (Schema::hasColumn('orders', 'paid_at')) {
            $query->orderByDesc('paid_at');
        }

        return $query->orderByDesc('id');
    }

    /**
     * @return Collection<int, Order>
     */
    public function oldestMissingTaxOrders(int $limit = 5): Collection
    {
        if (! Schema::hasTable('orders') || ! Invoice::tableAvailable()) {
            return collect();
        }

        try {
            $query = $this->missingTaxInvoiceBase()->with('user:id,name,email');
            if (Schema::hasColumn('orders', 'paid_at')) {
                // A paid order with no paid_at still waits; sort it by created_at
                // so a blank paid date does not jump ahead of an older one.
                $query->orderByRaw('COALESCE(paid_at, created_at)');
            } else {
                $query->orderBy('created_at');
            }

            return $query->orderBy('id')->limit($limit)->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * @return Builder<Invoice>
     */
    public function missingPdfInvoices(): Builder
    {
        return Invoice::query()->where(function ($q) {
            $q->whereNull('pdf_path')->orWhere('pdf_path', '');
        });
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function oldestMissingPdfInvoices(int $limit = 5): Collection
    {
        if (! Schema::hasColumn('invoices', 'pdf_path')) {
            return collect();
        }

        try {
            $query = $this->missingPdfInvoices();
            if (Schema::hasColumn('invoices', 'invoice_date')) {
                // invoice_date is a timestamp. Comparing it to '' fails under
                // strict SQL mode, so only fall back when the value is null.
                $query->orderByRaw('COALESCE(invoice_date, created_at)');
            } else {
                $query->orderBy('created_at');
            }

            return $query->orderBy('id')->limit($limit)->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * @return Builder<Order>
     */
    private function missingTaxInvoiceBase(): Builder
    {
        return Order::query()
            ->where('payment_status', 'paid')
            ->whereDoesntHave('invoices', function ($q) {
                $q->where('type', Invoice::TYPE_TAX_INVOICE)
                    ->where('status', '!=', Invoice::STATUS_CANCELLED);
            });
    }
}
