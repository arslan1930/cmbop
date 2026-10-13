<?php

namespace App\Mail;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Wallet;
use App\Services\Billing\DepositReceiptService;
use App\Support\DepositFx;
use App\Support\EmailCatalog;

class DepositApproved extends PlatformMailable
{
    public DepositRequest $deposit;

    public ?float $receivedCharge;

    public ?float $creditedEuros;

    public function __construct(
        DepositRequest $deposit,
        ?float $receivedCharge = null,
        ?float $creditedEuros = null,
    ) {
        parent::__construct();

        $deposit->loadMissing('user');
        $this->deposit = $deposit;
        $this->receivedCharge = $receivedCharge;
        $this->creditedEuros = $creditedEuros;
        $this->notificationType = 'deposit_approved';
        $this->recipientUser = $deposit->user;
        $this->dedupeKey = 'deposit_approved:'.$deposit->id;
    }

    public function build()
    {
        $deposit = $this->deposit->loadMissing('user');
        $method = strtolower((string) ($deposit->payment_method ?? ''));
        $isCard = $method === 'card';
        $isPaypal = $method === 'paypal';
        $isInstant = $isCard || $isPaypal;
        $amount = number_format((float) $deposit->amount, 2);
        $receipt = $this->resolveReceipt($deposit);

        $subject = $isInstant
            ? 'Wallet topped up — €'.$amount
            : 'Deposit Approved - €'.$amount;

        $advertiserWallet = null;
        if ($deposit->user && ! EmailCatalog::isPreviewUser($deposit->user)) {
            $advertiserRoleId = Wallet::advertiserRoleId();
            $advertiserWallet = $advertiserRoleId
                ? $deposit->user->wallets()->where('role_id', $advertiserRoleId)->first()
                : null;
        }

        $mail = $this->subject($subject)
            ->markdown('emails.deposit-approved', [
                'deposit' => $deposit,
                'isCard' => $isCard,
                'isPaypal' => $isPaypal,
                'isInstant' => $isInstant,
                'receipt' => $receipt,
                'walletBalance' => (float) ($advertiserWallet?->balance ?? 0),
                'balanceUrl' => $this->customerFacingRoute('advertiser.balance'),
                'downloadReceiptUrl' => $receipt
                    ? $this->advertiserBillingDownloadUrl($receipt)
                    : null,
                'approvalFxLine' => $this->approvalFxLine($deposit),
            ]);

        if ($receipt) {
            $this->attachInvoicePdfIfLive($mail, $receipt, $receipt->invoice_number.'.pdf');
        }

        return $mail;
    }

    private function approvalFxLine(DepositRequest $deposit): ?string
    {
        $method = strtolower((string) ($deposit->payment_method ?? ''));
        if (in_array($method, ['card', 'paypal'], true)) {
            return null;
        }

        $received = $this->receivedCharge ?? app(DepositFx::class)->expectedCharge($deposit);
        $credited = $this->creditedEuros ?? (float) $deposit->amount;

        return app(DepositFx::class)->approvalEmailLine($deposit, $received, $credited);
    }

    protected function resolveReceipt(DepositRequest $deposit): ?Invoice
    {
        if ((int) $deposit->id === 0 || $deposit->reference_code === 'DEP-PREVIEW') {
            return null;
        }

        try {
            return app(DepositReceiptService::class)->issue($deposit);
        } catch (\Throwable) {
            return app(DepositReceiptService::class)->find($deposit);
        }
    }
}
