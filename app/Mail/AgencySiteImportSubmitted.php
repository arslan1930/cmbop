<?php

namespace App\Mail;

use App\Models\AgencySiteImport;

class AgencySiteImportSubmitted extends PlatformMailable
{
    public AgencySiteImport $import;

    public function __construct(AgencySiteImport $import)
    {
        parent::__construct();
        $this->import = $import->loadMissing(['publisher']);
        $this->notificationType = 'agency_site_import_submitted';
        $this->skipUserPreference = true;
        // Per-recipient dedupeKey is set by the notifier so every admin gets mail.
    }

    public function build()
    {
        $publisher = $this->import->publisher;
        $created = (int) $this->import->created_count;
        $failed = (int) $this->import->failed_count;

        return $this->subject("Agency CSV import #{$this->import->id}: {$created} site(s) for review")
            ->markdown('emails.agency-site-import-submitted')
            ->with([
                'import' => $this->import,
                'publisherName' => $publisher?->name ?? 'Publisher',
                'publisherEmail' => $publisher?->email ?? 'Unknown',
                'createdCount' => $created,
                'failedCount' => $failed,
                'adminUrl' => route('admin.agency-imports.show', $this->import),
            ]);
    }
}
