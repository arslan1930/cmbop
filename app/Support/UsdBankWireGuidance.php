<?php

namespace App\Support;

use App\Models\User;
use App\Models\Wallet;

/**
 * Bank details stay EUR (IBAN/SWIFT). USD display (or a USD wallet) gets the
 * international-wire note. Travel IP never overrides a saved EUR preference.
 */
class UsdBankWireGuidance
{
    public function __construct(
        private MoneyDisplay $money,
    ) {}

    public function applies(?string $walletCurrency = null, ?User $user = null): bool
    {
        if ($this->money->currency() === 'USD') {
            return true;
        }

        $currency = $this->normalizedCurrency($walletCurrency);
        if ($currency === '' && $user) {
            $currency = $this->walletCurrencyFor($user);
        }

        return $currency === 'USD';
    }

    public function walletCurrencyFor(?User $user): string
    {
        if (! $user) {
            return '';
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return '';
        }

        try {
            $currency = Wallet::query()
                ->where('user_id', $user->id)
                ->where('role_id', $roleId)
                ->value('currency');
        } catch (\Throwable) {
            return '';
        }

        return $this->normalizedCurrency($currency);
    }

    /**
     * EUR ledger amount (Add Funds / invoice / checkout) → EUR to send + USD estimate.
     *
     * @return array{
     *     euros: float,
     *     usd: float,
     *     rate: float,
     *     eur_label: string,
     *     usd_label: string,
     *     send_label: string
     * }
     */
    public function quoteFromEuros(float $euros): array
    {
        $euros = round(max(0, $euros), 2);
        $usd = $this->money->fromEuros($euros, 'USD');

        return $this->quote($euros, $usd);
    }

    /**
     * USD top-up → EUR the advertiser must wire, via {@see MoneyDisplay::toEuros()}.
     *
     * @return array{
     *     euros: float,
     *     usd: float,
     *     rate: float,
     *     eur_label: string,
     *     usd_label: string,
     *     send_label: string
     * }
     */
    public function quoteFromUsd(float $usd): array
    {
        $usd = round(max(0, $usd), 2);
        $euros = $this->money->toEuros($usd, 'USD');

        return $this->quote($euros, $usd);
    }

    /**
     * @return array{
     *     show: bool,
     *     euros: float,
     *     usd: float,
     *     rate: float,
     *     eur_label: string,
     *     usd_label: string,
     *     send_label: ?string,
     *     send_template: string,
     *     wire_note: string,
     *     avoid_fees: string,
     *     card_label: string,
     *     paypal_label: string
     * }
     */
    public function viewPayload(
        ?string $walletCurrency = null,
        ?User $user = null,
        ?float $eurAmount = null,
    ): array {
        $show = $this->applies($walletCurrency, $user);
        $quote = $this->quoteFromEuros($eurAmount ?? 0);

        return [
            'show' => $show,
            'euros' => $quote['euros'],
            'usd' => $quote['usd'],
            'rate' => $quote['rate'],
            'eur_label' => $quote['eur_label'],
            'usd_label' => $quote['usd_label'],
            'send_label' => $show ? $quote['send_label'] : null,
            'send_template' => (string) __('messages.usd_bank_send_label'),
            'wire_note' => (string) __('messages.usd_bank_wire_note'),
            'avoid_fees' => (string) __('messages.usd_bank_avoid_fees'),
            'card_label' => (string) __('messages.usd_bank_pay_card'),
            'paypal_label' => (string) __('messages.usd_bank_pay_paypal'),
        ];
    }

    /**
     * @return array{show: bool, rate: float, sendTemplate: string}
     */
    public function bootPayload(?string $walletCurrency = null, ?User $user = null): array
    {
        return [
            'show' => $this->applies($walletCurrency, $user),
            'rate' => $this->money->usdPerEur(),
            'sendTemplate' => (string) __('messages.usd_bank_send_label'),
        ];
    }

    /**
     * Plain-text lines for invoice notes / emailed pay instructions.
     *
     * @return list<string>
     */
    public function instructionLines(float $eurAmount, ?string $walletCurrency = null, ?User $user = null): array
    {
        if (! $this->applies($walletCurrency, $user)) {
            return [];
        }

        return [
            (string) __('messages.usd_bank_wire_note'),
            (string) __('messages.usd_bank_avoid_fees'),
        ];
    }

    /**
     * @return array{
     *     euros: float,
     *     usd: float,
     *     rate: float,
     *     eur_label: string,
     *     usd_label: string,
     *     send_label: string
     * }
     */
    private function quote(float $euros, float $usd): array
    {
        $eurLabel = '€'.number_format($euros, 2);
        $usdLabel = '$'.number_format($usd, 2);

        return [
            'euros' => $euros,
            'usd' => $usd,
            'rate' => $this->money->usdPerEur(),
            'eur_label' => $eurLabel,
            'usd_label' => $usdLabel,
            'send_label' => app(DepositFx::class)->sendLabel('EUR', $euros, $euros),
        ];
    }

    private function normalizedCurrency(mixed $currency): string
    {
        return strtoupper(trim((string) $currency));
    }
}
