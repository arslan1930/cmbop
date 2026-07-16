<?php
// app/Mail/WithdrawalStatusUpdated.php

namespace App\Mail;


class WithdrawalStatusUpdated extends PlatformMailable
{

    public $withdrawal;
    public $oldStatus;
    public $newStatus;
    public $notes;

    public function __construct($withdrawal, $oldStatus, $newStatus, $notes)
    {
        parent::__construct();
        $this->withdrawal = $withdrawal;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->notes = $notes;
    }

    public function build()
    {
        return $this->subject('Withdrawal Request ' . ucfirst($this->newStatus))
                    ->markdown('emails.publisher.withdrawal-status-updated')
                    ->with([
                        'withdrawal' => $this->withdrawal,
                        'oldStatus' => $this->oldStatus,
                        'newStatus' => $this->newStatus,
                        'notes' => $this->notes
                    ]);
    }
}