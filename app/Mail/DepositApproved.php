<?php

namespace App\Mail;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Wallet;
use App\Services\Billing\DepositReceiptService;
use App\Services\Billing\InvoicePdfGenerator;

class DepositApproved extends PlatformMailable
{
    public DepositRequest $deposit;

    public function __construct(DepositRequest $deposit)
    {
        parent::__construct();

        $deposit->loadMissing('user');
        $this->deposit = $deposit;
        $this->notificationType = 'deposit_approved';
        $this->recipientUser = $deposit->user;
        $this->dedupeKey = 'deposit_approved:'.$deposit->id;
    }

    public function build()
    {
        $deposit = $this->deposit->loadMissing('user');
        $isCard = strtolower((string) ($deposit->payment_method ?? '')) === 'card';
        $amount = number_format((float) $deposit->amount, 2);
        $receipt = $this->resolveReceipt($deposit);

        $subject = $isCard
            ? 'Wallet topped up — €'.$amount
            : 'Deposit Approved - €'.$amount;

        $advertiserRoleId = Wallet::advertiserRoleId();
        $advertiserWallet = $advertiserRoleId
            ? $deposit->user?->wallets()->where('role_id', $advertiserRoleId)->first()
            : null;

        $mail = $this->subject($subject)
            ->markdown('emails.deposit-approved', [
                'deposit' => $deposit,
                'isCard' => $isCard,
                'receipt' => $receipt,
                'walletBalance' => (float) ($advertiserWallet?->balance ?? 0),
                'balanceUrl' => $this->publicRoute('advertiser.balance'),
                'downloadReceiptUrl' => $receipt
                    ? $this->publicRoute('advertiser.billing.download', $receipt)
                    : null,
            ]);

        if ($receipt) {
            $path = app(InvoicePdfGenerator::class)->absolutePath($receipt);
            if ($path && is_readable($path)) {
                $mail->attach($path, [
                    'as' => $receipt->invoice_number.'.pdf',
                    'mime' => 'application/pdf',
                ]);
            } elseif ($receipt->pdf_path && $receipt->pdfExists()) {
                // Storage fakes / remote disks may not expose a readable local path.
                $mail->attachFromStorageDisk(
                    $receipt->pdf_disk ?: config('billing.storage.disk', 'local'),
                    $receipt->pdf_path,
                    $receipt->invoice_number.'.pdf',
                    ['mime' => 'application/pdf']
                );
            }
        }

        return $mail;
    }

    protected function resolveReceipt(DepositRequest $deposit): ?Invoice
    {
        if ((int) $deposit->id <= 0) {
            return null;
        }

        try {
            return app(DepositReceiptService::class)->issue($deposit);
        } catch (\Throwable) {
            return app(DepositReceiptService::class)->find($deposit);
        }
    }
}
