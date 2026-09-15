<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only mail/queue counters shared by Email Center and the admin dashboard.
 */
class OpsHealthService
{
    /**
     * @return array{
     *     tone: string,
     *     pending_jobs: int,
     *     failed_jobs: int,
     *     mail_pending_jobs: int,
     *     mail_failed_jobs: int,
     *     auto_drain: bool,
     *     connection: string,
     *     mail_connection: string,
     *     mail_queue: string,
     *     url: string
     * }
     */
    public function snapshot(): array
    {
        $pending = $this->tableCount('jobs');
        $failed = $this->tableCount('failed_jobs');
        $mailPending = $this->mailJobsCount('jobs');
        $mailFailed = $this->mailJobsCount('failed_jobs');
        $autoDrain = (bool) config('email_notifications.auto_drain');
        $connection = (string) config('queue.default');
        $mailConnection = (string) config('email_notifications.queue_connection', $connection);

        $tone = 'ok';
        if ($failed > 0 || $mailFailed > 0) {
            $tone = 'fail';
        } elseif ($mailPending > 0 || ($pending > 0 && ! $autoDrain && $mailConnection !== 'sync')) {
            $tone = 'warn';
        }

        return [
            'tone' => $tone,
            'pending_jobs' => $pending,
            'failed_jobs' => $failed,
            'mail_pending_jobs' => $mailPending,
            'mail_failed_jobs' => $mailFailed,
            'auto_drain' => $autoDrain,
            'connection' => $connection,
            'mail_connection' => $mailConnection,
            'mail_queue' => (string) config('email_notifications.queue', 'emails'),
            'url' => route('admin.emails.index'),
        ];
    }

    public function mailPendingCount(): int
    {
        return $this->mailJobsCount('jobs');
    }

    public function mailFailedCount(): int
    {
        return $this->mailJobsCount('failed_jobs');
    }

    private function tableCount(string $table): int
    {
        try {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            return (int) DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function mailJobsCount(string $table): int
    {
        try {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            return (int) DB::table($table)->where($this->mailPayloadConstraint())->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Same match Email Center uses for “mail” vs every other queued job.
     */
    public function mailPayloadConstraint(): \Closure
    {
        return function ($q) {
            $q->where('payload', 'like', '%SendQueuedMailable%');
        };
    }
}
