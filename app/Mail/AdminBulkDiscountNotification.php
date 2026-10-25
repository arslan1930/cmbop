<?php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;
use App\Support\BulkDiscount;

class AdminBulkDiscountNotification extends PlatformMailable
{
    /**
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $fromTiers
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $toTiers
     */
    public function __construct(
        public Site $site,
        User $publisher,
        public array $fromTiers,
        public array $toTiers,
        public string $change = 'set',
    ) {
        parent::__construct();
        $this->notificationType = 'admin_bulk_discount';
        $this->recipientUser = $publisher;
        $this->change = in_array($change, ['set', 'updated', 'removed'], true) ? $change : 'set';
        $this->dedupeKey = 'admin-bulk-discount:'.$site->id.':'.$this->change.':'
            .md5((BulkDiscount::label($fromTiers) ?? 'off').'|'.(BulkDiscount::label($toTiers) ?? 'off'));
    }

    public function build()
    {
        $fromLabel = BulkDiscount::label($this->fromTiers);
        $toLabel = BulkDiscount::label($this->toTiers);
        $subject = match ($this->change) {
            'removed' => 'Pack discount removed — '.$this->site->site_name,
            'updated' => 'Pack discount updated — '.$this->site->site_name,
            default => 'Pack discount added — '.$this->site->site_name,
        };

        return $this->subject($subject)
            ->markdown('emails.admin-bulk-discount')
            ->with([
                'site' => $this->site,
                'publisherName' => $this->recipientUser->name ?? 'Publisher',
                'change' => $this->change,
                'fromLabel' => $fromLabel,
                'toLabel' => $toLabel,
                'manageUrl' => $this->publicRoute('publisher.websites'),
            ]);
    }
}
