<?php

namespace App\Services;

use App\Mail\AgencySiteImportSubmitted;
use App\Models\AgencySiteImport;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AgencySiteImportNotifier
{
    public function __construct(
        private InAppNotificationService $notifications,
    ) {}

    public function notifySubmitted(AgencySiteImport $import): void
    {
        if ($import->dry_run || (int) $import->created_count <= 0) {
            return;
        }

        $import->loadMissing(['publisher']);

        try {
            $this->notifications->notifyAdminsAgencySiteImportSubmitted($import);
        } catch (\Throwable $e) {
            Log::warning('Failed to bell admins about agency CSV import: '.$e->getMessage(), [
                'import_id' => $import->id,
            ]);
        }

        try {
            $admins = User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
                ->get();
            $recipients = $admins->isNotEmpty()
                ? $admins
                : collect([(object) ['email' => config('mail.admin_email')]]);

            foreach ($recipients as $admin) {
                if (empty($admin->email)) {
                    continue;
                }

                $mailable = new AgencySiteImportSubmitted($import);
                if ($admin instanceof User) {
                    $mailable->recipientUser = $admin;
                    $mailable->dedupeKey = 'agency-import-submitted-'.$import->id.':admin:'.$admin->id;
                } else {
                    $mailable->dedupeKey = 'agency-import-submitted-'.$import->id.':fallback:'.strtolower((string) $admin->email);
                }

                Mail::to($admin->email)->send($mailable);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email admins about agency CSV import: '.$e->getMessage(), [
                'import_id' => $import->id,
            ]);
        }
    }
}
