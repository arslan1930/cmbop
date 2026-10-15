<?php

namespace App\Console\Commands;

use App\Mail\BackupFailedMail;
use App\Services\Backup\BackupArchiveBuilder;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\GoogleDriveClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BackupToGoogleDrive extends Command
{
    protected $signature = 'backup:drive
                            {--test : Verify Google Drive credentials without uploading a dump}';

    protected $description = 'Dump the database and media, then upload to Google Drive (keep last 30)';

    public function handle(DatabaseDumper $dumper, BackupArchiveBuilder $archives, GoogleDriveClient $drive): int
    {
        @set_time_limit(0);

        if (! $drive->configured()) {
            $message = 'Google Drive backup is not configured (GOOGLE_DRIVE_CLIENT_ID/SECRET/REFRESH_TOKEN/FOLDER_ID).';
            $this->error($message);
            if (! $this->option('test')) {
                $this->notifyAdmin($message);
            }

            return self::FAILURE;
        }

        try {
            if ($this->option('test')) {
                $info = $drive->testCredentials();
                $this->info('Google Drive credentials ok. Folder: '.$info['folder_name'].' ('.$info['folder_id'].')');

                return self::SUCCESS;
            }

            $tmpSql = storage_path('app/backup-tmp/database-'.uniqid('', true).'.sql');
            $zipPath = null;
            try {
                $this->info('Dumping database…');
                $dumper->dumpTo($tmpSql);
                $this->info('Building zip…');
                $zipPath = $archives->buildZip($tmpSql);
                $name = basename($zipPath);
                $this->info('Uploading '.$name.'…');
                $id = $drive->uploadFile($zipPath, $name);
                $this->info('Uploaded file id '.$id);
                $deleted = $drive->pruneOwnPrefixedFiles((int) config('backup.keep', 30));
                if ($deleted > 0) {
                    $this->info('Pruned '.$deleted.' older backup(s).');
                }
            } finally {
                $archives->forgetPath($tmpSql);
                $archives->forgetPath($zipPath);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->notifyAdmin($e->getMessage());

            return self::FAILURE;
        }
    }

    private function notifyAdmin(string $error): void
    {
        $to = trim((string) config('mail.admin_email'));
        if ($to === '') {
            $this->warn('ADMIN_EMAIL is empty; backup failure was not emailed.');

            return;
        }

        try {
            Mail::to($to)->send(new BackupFailedMail($error));
        } catch (Throwable $e) {
            $this->warn('Could not email backup failure: '.$e->getMessage());
        }
    }
}
