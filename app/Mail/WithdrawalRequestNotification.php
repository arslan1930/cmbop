<?php

namespace App\Mail;

use App\Services\Billing\BillingRuleService;
use App\Services\Wallet\ManualWithdrawalMarkPaidLink;

/**
 * Admin alert: a publisher (or advertiser) requested a withdrawal. Primary CTA
 * opens the signed mark-paid confirm page (settles only after confirm).
 */
class WithdrawalRequestNotification extends PlatformMailable
{
    public $withdrawal;

    public $user;

    public $platformChargePercent;

    public function __construct($withdrawal, $user)
    {
        parent::__construct();
        $this->withdrawal = $withdrawal;
        $this->user = $user;
        $this->platformChargePercent = app(BillingRuleService::class)->withdrawalFeePercent();
        $this->notificationType = 'withdrawal_request';
    }

    public function build()
    {
        return $this->subject('New Withdrawal Request - €'.number_format($this->withdrawal->amount, 2))
            ->markdown('emails.publisher.withdrawal-request')
            ->with([
                'withdrawal' => $this->withdrawal,
                'user' => $this->user,
                'platformChargePercent' => $this->platformChargePercent,
                'markPaidUrl' => ManualWithdrawalMarkPaidLink::url($this->withdrawal),
                'adminUrl' => $this->publicRoute('admin.withdrawals'),
            ]);
    }
}
