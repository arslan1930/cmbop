<?php

namespace App\Services\Partners;

use App\Mail\PartnerThreadMessageMail;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerOrderAttachment;
use App\Models\PartnerOrderMessage;
use App\Models\User;
use App\Support\PartnerDashboard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PartnerThreadService
{
    public function __construct(
        private PartnerContactMasker $masker,
        private PartnerAlertService $alerts,
        private PartnerWebhookService $webhooks,
        private PartnerOrderPresenter $presenter,
    ) {}

    /**
     * @param  array{item_type?: string, visibility?: string, hold?: bool, relay_status?: string, attachment_ids?: list<int>}  $options
     */
    public function post(OrderItem $item, string $authorType, ?int $authorId, string $body, array $options = []): PartnerOrderMessage
    {
        $allowed = array_filter([
            (string) $item->target_url,
            (string) $item->live_url,
            (string) ($item->site?->domain ?? ''),
        ]);
        $names = array_filter([
            (string) ($item->order?->user?->name ?? ''),
            (string) ($item->order?->user?->email ?? ''),
            (string) ($item->site?->publisher?->name ?? ''),
        ]);
        $result = $this->masker->mask($body, $allowed, $names);
        $hold = (bool) ($options['hold'] ?? false) || $result['held'];
        $relay = $options['relay_status'] ?? ($hold ? 'held_for_review' : 'relayed');
        $visibility = $options['visibility'] ?? 'all';
        if ($relay === 'held_for_review' && $visibility === 'all') {
            $visibility = $authorType === 'buyer' ? 'buyer' : ($authorType === 'partner' ? 'partner' : 'admin');
        }

        $message = PartnerOrderMessage::query()->create([
            'order_item_id' => $item->id,
            'author_type' => $authorType,
            'author_id' => $authorId,
            'item_type' => $options['item_type'] ?? 'message',
            'visibility' => $visibility,
            'body_masked' => $result['masked'],
            'body_original_encrypted' => Crypt::encryptString($result['original']),
            'masked' => $result['rules'] !== [],
            'mask_rules' => $result['rules'],
            'relay_status' => $relay,
        ]);

        if (! empty($options['attachment_ids'])) {
            PartnerOrderAttachment::query()
                ->whereIn('id', $options['attachment_ids'])
                ->where('order_item_id', $item->id)
                ->update(['message_id' => $message->id]);
        }

        if ($relay === 'relayed' && in_array($authorType, ['buyer', 'admin', 'system'], true)) {
            $this->notifyPartner($item, $message);
        }

        return $message;
    }

    public function release(PartnerOrderMessage $message, User $admin): PartnerOrderMessage
    {
        $message->relay_status = 'relayed';
        $message->visibility = 'all';
        $message->save();
        $item = OrderItem::query()->find($message->order_item_id);
        if ($item) {
            $this->notifyPartner($item, $message);
        }

        return $message;
    }

    public function hide(PartnerOrderMessage $message): PartnerOrderMessage
    {
        $message->relay_status = 'hidden';
        $message->visibility = 'admin';
        $message->save();

        return $message;
    }

    /**
     * @return list<PartnerOrderMessage>
     */
    public function visibleToPartner(OrderItem $item)
    {
        return PartnerOrderMessage::query()
            ->with('attachments')
            ->where('order_item_id', $item->id)
            ->whereIn('visibility', ['all', 'partner'])
            ->where('relay_status', 'relayed')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Admin sees originals.
     *
     * @return list<PartnerOrderMessage>
     */
    public function visibleToAdmin(OrderItem $item)
    {
        return PartnerOrderMessage::query()
            ->with('attachments')
            ->where('order_item_id', $item->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function storeAttachment(OrderItem $item, UploadedFile $file, ?User $uploader = null): PartnerOrderAttachment
    {
        $name = $file->getClientOriginalName();
        $masked = $this->masker->maskFilename($name);
        $path = $file->store('partner-attachments/'.$item->id, 'local');

        return PartnerOrderAttachment::query()->create([
            'order_item_id' => $item->id,
            'uploaded_by' => $uploader?->id,
            'filename_original' => $name,
            'filename_masked' => $masked,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'storage_path' => $path,
            'scan_status' => 'skipped',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function partnerPayload(OrderItem $item): array
    {
        $rows = [];
        foreach ($this->visibleToPartner($item) as $message) {
            $rows[] = [
                'id' => $message->id,
                'author_type' => $message->author_type === 'buyer' ? 'slb' : $message->author_type,
                'item_type' => $message->item_type,
                'body' => $message->body_masked,
                'masked' => (bool) $message->masked,
                'relay_status' => $message->relay_status,
                'attachments' => $message->attachments->map(fn (PartnerOrderAttachment $a) => [
                    'id' => $a->id,
                    'filename' => $a->filename_masked,
                ])->all(),
                'created_at' => optional($message->created_at)?->toIso8601String(),
            ];
        }

        return $rows;
    }

    private function notifyPartner(OrderItem $item, PartnerOrderMessage $message): void
    {
        $publisher = $item->site?->publisher;
        if (! $publisher instanceof User || ! $publisher->isSupplyPartner()) {
            return;
        }
        $partner = Partner::forUser($publisher);
        if (! $partner || ! $partner->wantsNotification('messages')) {
            return;
        }
        if (! class_exists(PartnerThreadMessageMail::class)) {
            return;
        }
        $url = $this->alertsOrderUrl($publisher, $item, $partner);
        Mail::to($publisher->email)->send(new PartnerThreadMessageMail(
            $publisher,
            $item,
            Str::limit(strip_tags((string) $message->body_masked), 140, ''),
            $url
        ));
        $this->webhooks->emit($partner, 'order.message_created', [
            'object' => ['id' => 'ord_'.$item->id, 'preview' => Str::limit((string) $message->body_masked, 140, '')],
        ]);
    }

    private function alertsOrderUrl(User $publisher, OrderItem $item, Partner $partner): string
    {
        if ($partner->oneClickLinksEnabled() && Schema::hasTable('partner_email_action_tokens')) {
            $token = app(PartnerEmailActionService::class)->issue(
                $publisher,
                (int) $item->id,
                ['view', 'reply']
            );

            return PartnerDashboard::origin().'/a/'.$token;
        }

        return PartnerDashboard::origin().'/orders/'.$item->id;
    }
}
