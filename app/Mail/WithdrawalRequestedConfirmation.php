<?php

namespace App\Mail;

use App\Models\Withdrawal;

/**
 * Publisher confirmation that a withdrawal request was submitted.
 */
class WithdrawalRequestedConfirmation extends PlatformMailable
{
    public function __construct(public Withdrawal $withdrawal)
    {
        parent::__construct();
        $this->notificationType = 'withdrawal_requested_confirmation';
        $this->recipientUser = $withdrawal->user;
    }

    protected function dedupeVariant(): ?string
    {
        return (string) $this->withdrawal->id;
    }

    public function build()
    {
        return $this->subject('Withdrawal request received (WD-'.$this->withdrawal->id.')')
            ->markdown('emails.publisher.withdrawal-requested')
            ->with([
                'withdrawal' => $this->withdrawal,
                'userName' => $this->withdrawal->user?->name ?: 'Publisher',
                'withdrawUrl' => $this->publicRoute('publisher.withdraw'),
            ]);
    }
}
