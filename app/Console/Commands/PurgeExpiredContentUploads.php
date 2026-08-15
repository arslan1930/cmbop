<?php

namespace App\Console\Commands;

use App\Models\ContentSubmission;
use Illuminate\Console\Command;

class PurgeExpiredContentUploads extends Command
{
    protected $signature = 'content:purge-expired';

    protected $description = 'Strip original Word files from unused expired articles (keep preview rows; skip open order claims)';

    public function handle(): int
    {
        // Never strip articles still on an open owner or a non-cancelled
        // (non-clawed) order item. Cancelled leftover rows keep
        // content_submission_id and must not block retention strip.
        $query = ContentSubmission::query()
            ->expiredUnused()
            ->withoutOpenOrderItemLink()
            ->where('path', '!=', '')
            ->whereNotNull('path')
            ->limit(200);

        $count = 0;
        $query->each(function (ContentSubmission $submission) use (&$count) {
            $submission->stripStoredFileKeepPreview();
            $count++;
        });

        $this->info("Stripped {$count} unused expired Word file(s). Preview rows were kept. Linked/in-use articles were left alone.");

        return self::SUCCESS;
    }
}
