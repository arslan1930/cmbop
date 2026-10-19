<?php

namespace App\Services\Partners;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerOrderCorrection;
use App\Models\PartnerOrderDispute;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class PartnerCorrectionService
{
    public function __construct(
        private PartnerThreadService $thread,
        private PartnerWebhookService $webhooks,
        private PartnerAlertService $alerts,
    ) {}

    /**
     * @return array{kind: string, correction?: PartnerOrderCorrection, dispute?: PartnerOrderDispute}
     */
    public function buyerRequest(OrderItem $item, string $instructions, ?User $buyer = null): array
    {
        $partner = PartnerChannel::partnerForItem($item);
        $used = (int) PartnerOrderCorrection::query()->where('order_item_id', $item->id)->count();
        $message = $this->thread->post($item, 'buyer', $buyer?->id, $instructions, [
            'item_type' => 'correction_request',
            'hold' => true,
        ]);

        if ($used >= 2) {
            $dispute = $this->openDispute($item, 'admin', 'third_correction', $instructions, null);
            $this->thread->post($item, 'system', null, 'A third change request opened a dispute. SLB support will decide.', [
                'item_type' => 'dispute',
                'visibility' => 'all',
            ]);

            return ['kind' => 'dispute', 'dispute' => $dispute];
        }

        $correction = PartnerOrderCorrection::query()->create([
            'order_item_id' => $item->id,
            'number' => $used + 1,
            'instructions' => $instructions,
            'requested_by_message_id' => $message->id,
            'status' => PartnerOrderCorrection::PENDING_RELAY,
        ]);
        if (Schema::hasColumn('order_items', 'partner_awaiting_relay')) {
            $item->partner_awaiting_relay = true;
            $item->save();
        }

        return ['kind' => 'pending_relay', 'correction' => $correction];
    }

    public function relay(PartnerOrderCorrection $correction, User $admin): PartnerOrderCorrection
    {
        $item = OrderItem::query()->findOrFail($correction->order_item_id);
        $order = $item->order;
        $correction->status = PartnerOrderCorrection::OPEN;
        $correction->relayed_by = $admin->id;
        $correction->due_by = now()->addHours(72);
        $correction->save();

        $item->modification_requested = 'yes';
        $item->modification_requested_at = now();
        $item->completion_notes = $correction->instructions;
        $item->partner_awaiting_relay = false;
        $item->partner_corrections_used = (int) $item->partner_corrections_used + 1;
        $item->auto_approve_triggered = false;
        $item->save();
        if ($order instanceof Order && $order->status === 'review') {
            $order->status = 'processing';
            $order->save();
        }
        $this->thread->post($item, 'admin', $admin->id, 'Correction '.$correction->number.' of 2: '.$correction->instructions, [
            'item_type' => 'correction_request',
            'relay_status' => 'relayed',
        ]);
        $publisher = $item->site?->publisher;
        if ($publisher instanceof User) {
            $this->alerts->notifyCorrection($publisher, $item, $correction->instructions);
            $partner = Partner::forUser($publisher);
            if ($partner) {
                $this->webhooks->emit($partner, 'order.correction_requested', [
                    'object' => ['id' => 'ord_'.$item->id, 'status' => PartnerOrderStatus::AWAITING_CORRECTION],
                ]);
            }
        }

        return $correction->fresh();
    }

    public function decline(OrderItem $item, PartnerOrderCorrection $correction, string $reason): PartnerOrderDispute
    {
        $correction->status = PartnerOrderCorrection::DECLINED;
        $correction->decline_reason = $reason;
        $correction->save();
        $this->thread->post($item, 'partner', $item->site?->publisher_id, $reason, [
            'item_type' => 'correction_declined',
        ]);

        return $this->openDispute($item, 'partner_decline', 'correction_declined', $reason, $correction->id);
    }

    public function resolveOpen(OrderItem $item): void
    {
        PartnerOrderCorrection::query()
            ->where('order_item_id', $item->id)
            ->where('status', PartnerOrderCorrection::OPEN)
            ->update(['status' => PartnerOrderCorrection::RESOLVED, 'resolved_at' => now()]);
    }

    public function openDispute(OrderItem $item, string $openedBy, string $reason, string $message, ?int $correctionId): PartnerOrderDispute
    {
        $dispute = PartnerOrderDispute::query()->create([
            'order_item_id' => $item->id,
            'opened_by_type' => $openedBy,
            'correction_id' => $correctionId,
            'reason' => $reason,
            'message' => $message,
            'status' => PartnerOrderDispute::OPEN,
        ]);
        $item->partner_dispute_status = 'open';
        $item->partner_awaiting_relay = false;
        $item->save();
        $partner = PartnerChannel::partnerForItem($item);
        if ($partner) {
            $this->webhooks->emit($partner, 'order.disputed', [
                'object' => ['id' => 'ord_'.$item->id, 'status' => PartnerOrderStatus::DISPUTED],
            ]);
        }

        return $dispute;
    }

    public function resolve(PartnerOrderDispute $dispute, string $outcome, User $admin, ?string $note = null): PartnerOrderDispute
    {
        $dispute->status = $outcome === 'buyer'
            ? PartnerOrderDispute::RESOLVED_BUYER
            : PartnerOrderDispute::RESOLVED_PARTNER;
        $dispute->decided_by = $admin->id;
        $dispute->decided_at = now();
        $dispute->decision_note = $note;
        $dispute->save();
        $item = OrderItem::query()->find($dispute->order_item_id);
        if ($item) {
            $item->partner_dispute_status = $dispute->status;
            $item->save();
        }

        return $dispute;
    }
}
