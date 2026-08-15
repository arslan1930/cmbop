<?php

// app/Mail/WithdrawalStatusUpdated.php

namespace App\Mail;

use App\Services\Billing\WithdrawalPayoutStatementService;

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

    protected function dedupeVariant(): ?string
    {
        return $this->oldStatus.'>'.$this->newStatus;
    }

    public function build()
    {
        $statementUrl = null;
        $hasStatement = false;

        if ($this->newStatus === 'completed') {
            try {
                $statement = app(WithdrawalPayoutStatementService::class)
                    ->findExisting($this->withdrawal);
                if ($statement) {
                    $hasStatement = true;
                    $statementUrl = $this->publicRoute('publisher.billing.download', $statement);
                }
            } catch (\Throwable) {
                $hasStatement = false;
                $statementUrl = null;
            }
        }

        return $this->subject('Withdrawal Request '.ucfirst($this->newStatus))
            ->markdown('emails.publisher.withdrawal-status-updated')
            ->with([
                'withdrawal' => $this->withdrawal,
                'oldStatus' => $this->oldStatus,
                'newStatus' => $this->newStatus,
                'notes' => $this->notes,
                'statementUrl' => $statementUrl,
                'hasStatement' => $hasStatement,
                'documentsUrl' => $this->publicRoute('publisher.billing.index'),
                'withdrawUrl' => $this->publicRoute('publisher.withdraw'),
            ]);
    }
}
