<?php

namespace App\Console\Commands;

use App\Models\ContentSubmission;
use Illuminate\Console\Command;

class PurgeExpiredContentUploads extends Command
{
    protected $signature = 'content:purge-expired';

    protected $description = 'Delete expired content uploads (6-month retention)';

    public function handle(): int
    {
        // Never purge articles still linked to orders / order items.
        $query = ContentSubmission::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('order_id')
            ->whereDoesntHave('orderItem')
            ->whereDoesntHave('orderItems')
            ->limit(200);

        $count = 0;
        $query->each(function (ContentSubmission $submission) use (&$count) {
            $submission->deleteStoredFile();
            $submission->delete();
            $count++;
        });

        $this->info("Purged {$count} expired content submission(s).");

        return self::SUCCESS;
    }
}
