<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Backup failure must send inline. PlatformMailable is queued; Hostinger
 * often has no resident worker, and a backup that already failed should not
 * wait on the emails queue.
 */
class BackupFailedMail extends Mailable
{
    public function __construct(public string $errorMessage) {}

    public function build(): self
    {
        $safe = e($this->errorMessage);

        return $this->subject('Seolinkbuildings backup failed')
            ->html('<p>The daily Google Drive backup failed:</p><pre>'.$safe.'</pre>');
    }
}
