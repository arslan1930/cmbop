<?php

namespace App\Mail;

use App\Models\User;

class SpendBudgetAlertMail extends PlatformMailable
{
    public function __construct(
        public User $user,
        public string $kind,
        public array $status,
    ) {
        parent::__construct();
        $this->notificationType = 'spend_budget_alert';
        $this->recipientUser = $user;
        $this->dedupeKey = 'spend_budget_alert:'.$user->id.':'.$kind.':'.now()->format('Y-m-d');
    }

    public function build()
    {
        $titles = [
            'warn' => 'Spend budget warning',
            'hit' => 'Monthly spend budget reached',
            'low_balance' => 'Low wallet balance alert',
        ];

        return $this->subject($titles[$this->kind] ?? 'Spend budget update')
            ->markdown('emails.summaries.spend-budget-alert', [
                'user' => $this->user,
                'kind' => $this->kind,
                'status' => $this->status,
                'analyticsUrl' => $this->publicRoute('advertiser.analytics'),
                'addFundsUrl' => $this->publicRoute('advertiser.add-funds'),
            ]);
    }
}
