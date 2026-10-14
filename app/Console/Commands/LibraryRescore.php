<?php

namespace App\Console\Commands;

use App\Models\ContentSubmission;
use App\Services\ContentUpload\AdminLibraryStaffActions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LibraryRescore extends Command
{
    protected $signature = 'content:library-rescore
                            {--needs-fix : Only articles in Needs corrections}
                            {--zero-scores : Only rows with 0/empty quality and uniqueness}
                            {--limit=50 : Max articles to re-evaluate}';

    protected $description = 'Re-run Content Library quality/uniqueness scoring';

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

        if ($this->option('needs-fix')) {
            $query->where(function ($inner) {
                $inner->whereIn('moderation_status', [
                    ContentSubmission::STATUS_REJECTED,
                    ContentSubmission::STATUS_ERROR,
                    ContentSubmission::STATUS_NEEDS_IMPROVEMENT,
                ]);
            });
        }

        if ($this->option('zero-scores') && Schema::hasColumn('content_submissions', 'quality_score')) {
            $query->where(function ($inner) {
                $inner->whereNull('quality_score')->orWhere('quality_score', 0);
            })->where(function ($inner) {
                $inner->whereNull('uniqueness_score')->orWhere('uniqueness_score', 0);
            });
        }

        $rows = $query->limit($limit)->get();
        $done = 0;
        $skipped = 0;
        foreach ($rows as $submission) {
            try {
                $staff->retry($submission);
                $done++;
                $this->line('Re-scored #'.$submission->id);
            } catch (ValidationException $e) {
                $skipped++;
                $this->comment('Skipped #'.$submission->id.': '.collect($e->errors())->flatten()->first());
            } catch (\Throwable $e) {
                $skipped++;
                $this->warn('Failed #'.$submission->id.': '.$e->getMessage());
            }
        }

        $this->info('Re-scored '.$done.'; skipped '.$skipped.'.');

        return self::SUCCESS;
    }
}
