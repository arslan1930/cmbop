<?php

namespace App\Console\Commands;

use App\Models\ContentSubmission;
use Illuminate\Console\Command;

class PurgeExpiredContentUploads extends Command
{
    protected $signature = 'content:purge-expired';

    protected $description = 'Strip original Word files from unused expired articles (keep preview rows; skip anything linked to an order)';

    public function handle(): int
    {
        // Never strip articles still linked to orders / order items — only unused expired files.
        $query = ContentSubmission::query()
            ->expiredUnused()
            ->where('path', '!=', '')
            ->whereNotNull('path')
            ->whereDoesntHave('orderItem')
            ->whereDoesntHave('orderItems')
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
