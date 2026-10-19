<?php

namespace App\Services\Partners;

use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PartnerOrderQuery
{
    public function scopedItems(User $user): Builder
    {
        $query = OrderItem::query()
            ->with(['order', 'site'])
            ->whereHas('site', fn (Builder $q) => $q->where('publisher_id', $user->id))
            ->whereHas('order', function (Builder $q) {
                $q->where('payment_status', 'paid');
            });

        return $query;
    }

    public function findOwnOrFail(User $user, int $id): OrderItem
    {
        $item = $this->scopedItems($user)->where('order_items.id', $id)->first();
        if (! $item) {
            abort(404);
        }

        return $item;
    }

    public function counts(User $user): array
    {
        $counts = array_fill_keys(PartnerOrderStatus::TILES, 0);
        $items = $this->scopedItems($user)->get();
        foreach ($items as $item) {
            $status = PartnerOrderStatus::fromItem($item);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    public function paginate(User $user, Request $request): LengthAwarePaginator
    {
        $query = $this->scopedItems($user)->orderByDesc('order_items.id');

        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && in_array($status, PartnerOrderStatus::TILES, true)) {
            $query->where(function (Builder $q) use ($status) {
                $this->constrainStatus($q, $status);
            });
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('order_items.site_name', 'like', $like)
                    ->orWhere('order_items.site_url', 'like', $like)
                    ->orWhere('order_items.anchor_text', 'like', $like)
                    ->orWhere('order_items.target_url', 'like', $like)
                    ->orWhereHas('order', fn (Builder $order) => $order->where('order_number', 'like', $like)
                        ->orWhere('reference_code', 'like', $like));
            });
        }

        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));
        if ($from !== '') {
            $query->whereDate('order_items.created_at', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('order_items.created_at', '<=', $to);
        }

        return $query->paginate(20)->withQueryString();
    }

    private function constrainStatus(Builder $q, string $status): void
    {
        $hasMod = Schema::hasColumn('order_items', 'modification_requested');

        match ($status) {
            PartnerOrderStatus::REJECTED => $q->whereHas('order', fn (Builder $o) => $o->where('status', 'cancelled')),
            PartnerOrderStatus::COMPLETED => $q->whereHas('order', fn (Builder $o) => $o->where('status', 'completed')),
            PartnerOrderStatus::DISPUTED => Schema::hasColumn('order_items', 'partner_dispute_status')
                ? $q->where('order_items.partner_dispute_status', 'open')
                : $q->whereRaw('0=1'),
            PartnerOrderStatus::AWAITING_CORRECTION => $hasMod
                ? $q->where('order_items.modification_requested', 'yes')
                    ->where(function (Builder $d) {
                        if (Schema::hasColumn('order_items', 'partner_dispute_status')) {
                            $d->whereNull('order_items.partner_dispute_status')
                                ->orWhere('order_items.partner_dispute_status', '!=', 'open');
                        }
                    })
                    ->whereHas('order', fn (Builder $o) => $o->whereNotIn('status', ['cancelled', 'completed']))
                : $q->whereRaw('0=1'),
            PartnerOrderStatus::SUBMITTED => $q->whereHas('order', fn (Builder $o) => $o->where('status', 'review'))
                ->when($hasMod, fn (Builder $inner) => $inner->where(function (Builder $m) {
                    $m->whereNull('order_items.modification_requested')
                        ->orWhere('order_items.modification_requested', '!=', 'yes');
                })),
            PartnerOrderStatus::IN_PROGRESS => $q->whereHas('order', fn (Builder $o) => $o->where('status', 'processing'))
                ->when($hasMod, fn (Builder $inner) => $inner->where(function (Builder $m) {
                    $m->whereNull('order_items.modification_requested')
                        ->orWhere('order_items.modification_requested', '!=', 'yes');
                })),
            default => $q->whereHas('order', fn (Builder $o) => $o->whereIn('status', ['pending', 'scheduled'])),
        };
    }
}
