<?php

namespace App\Console\Commands;

use App\Models\ContentSubmission;
use App\Services\ContentUpload\AdminLibraryStaffActions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ScanLibrarySubmissions extends Command
{
    protected $signature = 'moderation:scan-submissions
                            {--missing-log : Only articles with no moderation_log_id}
                            {--limit=50 : Max articles to scan}';

    protected $description = 'Scan Content Library articles that have never been moderated';

    public function handle(AdminLibraryStaffActions $staff): int
    {
        if (! Schema::hasTable('content_submissions')) {
            $this->error('content_submissions is missing.');

            return self::FAILURE;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $query = ContentSubmission::query()->orderBy('id');
        if (Schema::hasColumn('content_submissions', 'archived_at')) {
            $query->whereNull('archived_at');
        }
        if ($this->option('missing-log') && Schema::hasColumn('content_submissions', 'moderation_log_id')) {
            $query->whereNull('moderation_log_id');
        }

        $rows = $query->limit($limit)->get();
        $done = 0;
        $skipped = 0;
        foreach ($rows as $submission) {
            try {
                $staff->retry($submission);
                $done++;
                $this->line('Scanned #'.$submission->id);
            } catch (ValidationException $e) {
                $skipped++;
                $this->comment('Skipped #'.$submission->id.': '.collect($e->errors())->flatten()->first());
            } catch (\Throwable $e) {
                $skipped++;
                $this->warn('Failed #'.$submission->id.': '.$e->getMessage());
            }
        }

        $this->info('Scanned '.$done.'; skipped '.$skipped.'.');

        return self::SUCCESS;
    }
}
