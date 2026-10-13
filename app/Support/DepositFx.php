<?php

namespace App\Support;

use App\Models\DepositRequest;
use Carbon\CarbonInterface;

/**
 * Bank/Wise charge currency lock. Ledger amount stays EUR.
 * fx_rate is stored as units of charge currency per €1 (same as FxRate::perEur).
 */
class DepositFx
{
    public function __construct(
        private MoneyDisplay $money,
        private FxRate $fx,
        private DisplayCurrencyPreference $preference,
    ) {}

    public function lockDays(): int
    {
        return max(1, (int) config('fx.deposit_lock_days', 7));
    }

    public function tolerancePercent(): float
    {
        $value = (float) config('fx.deposit_tolerance_percent', 2);

        return $value > 0 ? $value : 2.0;
    }

    public function toleranceNote(): string
    {
        return (string) config(
            'billing.fx_tolerance_note',
            'Because of exchange rates and bank fees, the amount we receive may differ slightly (about ±2%). We\'ll credit your wallet with the euro value of what actually arrives.'
        );
    }

    /**
     * @return 'EUR'|'GBP'
     */
    public function chargeCurrencyForPayFrom(?string $payFrom = null): string
    {
        $from = $this->preference->normalizePayFrom((string) $payFrom) ?? $this->preference->payFrom();

        return $from === 'gbp' ? 'GBP' : 'EUR';
    }

    /**
     * @return array{
     *     charge_currency: string,
     *     charge_amount: float,
     *     euros: float,
     *     fx_rate: float,
     *     rate_locked_until: \Carbon\CarbonInterface,
     *     send_label: string,
     *     eur_per_unit: float
     * }
     */
    public function quote(float $euros, string $chargeCurrency): array
    {
        $euros = round(max(0, $euros), 2);
        $code = strtoupper($chargeCurrency) === 'GBP' ? 'GBP' : 'EUR';
        $rate = $code === 'EUR' ? 1.0 : $this->fx->perEur('GBP');
        if ($rate <= 0) {
            $rate = 1.0;
        }
        $chargeAmount = $code === 'EUR' ? $euros : round($euros * $rate, 2);
        $lockedUntil = now()->addDays($this->lockDays());
        $eurPerUnit = $rate > 0 ? round(1 / $rate, 8) : 1.0;

        return [
            'charge_currency' => $code,
            'charge_amount' => $chargeAmount,
            'euros' => $euros,
            'fx_rate' => $rate,
            'rate_locked_until' => $lockedUntil,
            'send_label' => $this->sendLabel($code, $chargeAmount, $euros),
            'eur_per_unit' => $eurPerUnit,
        ];
    }

    public function sendLabel(string $chargeCurrency, float $chargeAmount, float $euros): string
    {
        $code = strtoupper($chargeCurrency) === 'GBP' ? 'GBP' : 'EUR';
        $local = $this->money->symbolFor($code).number_format($chargeAmount, 2);
        $eur = '€'.number_format($euros, 2);
        if ($code === 'EUR') {
            return 'Amount to send: '.$eur;
        }

        return 'Amount to send: '.$local.' (≈ '.$eur.' at today\'s rate, locked for '.$this->lockDays().' days)';
    }

    /**
     * @return array<string, mixed>
     */
    public function lockPayload(float $euros, string $chargeCurrency): array
    {
        $quote = $this->quote($euros, $chargeCurrency);

        return [
            'charge_currency' => $quote['charge_currency'],
            'charge_amount' => $quote['charge_amount'],
            'fx_rate' => $quote['fx_rate'],
            'rate_locked_until' => $quote['rate_locked_until'],
        ];
    }

    public function isLockExpired(DepositRequest $deposit): bool
    {
        if (! DepositRequest::hasTableColumn('rate_locked_until')) {
            return false;
        }
        $until = $deposit->rate_locked_until;
        if (! $until instanceof CarbonInterface) {
            return false;
        }

        return $until->isPast();
    }

    public function refreshIfExpired(DepositRequest $deposit): DepositRequest
    {
        if (! $this->isLockExpired($deposit)) {
            return $deposit;
        }

        $currency = strtoupper((string) ($deposit->charge_currency ?: 'EUR'));
        if ($currency === '') {
            $currency = 'EUR';
        }
        $payload = $this->lockPayload((float) $deposit->amount, $currency);
        $deposit->update(DepositRequest::attributesThatExist($payload));

        return $deposit->fresh() ?? $deposit;
    }

    public function eurosFromReceived(DepositRequest $deposit, float $receivedCharge): float
    {
        $receivedCharge = round(max(0, $receivedCharge), 2);
        $currency = strtoupper((string) ($deposit->charge_currency ?: 'EUR'));
        $rate = (float) ($deposit->fx_rate ?? 0);
        if ($currency !== 'GBP' || $rate <= 0) {
            return $receivedCharge;
        }

        return round($receivedCharge / $rate, 2);
    }

    public function expectedCharge(DepositRequest $deposit): float
    {
        $stored = $deposit->charge_amount;
        if ($stored !== null && $stored !== '') {
            return round((float) $stored, 2);
        }

        return round((float) $deposit->amount, 2);
    }

    public function withinTolerance(DepositRequest $deposit, float $receivedCharge): bool
    {
        $expected = $this->expectedCharge($deposit);
        if ($expected <= 0) {
            return true;
        }
        $delta = abs($receivedCharge - $expected);
        $limit = $expected * ($this->tolerancePercent() / 100);

        return $delta <= $limit + 0.00001;
    }

    public function adminExpectedLine(DepositRequest $deposit): string
    {
        $deposit = $this->refreshIfExpired($deposit);
        $currency = strtoupper((string) ($deposit->charge_currency ?: 'EUR'));
        $charge = $this->expectedCharge($deposit);
        $euros = round((float) $deposit->amount, 2);
        $rate = (float) ($deposit->fx_rate ?? 1);
        $eurPerUnit = $rate > 0 ? round(1 / $rate, 4) : 1.0;
        $until = $deposit->rate_locked_until;
        $untilLabel = $until instanceof CarbonInterface ? $until->format('M d, Y') : '—';
        $symbol = $this->money->symbolFor($currency === 'GBP' ? 'GBP' : 'EUR');

        return 'Expected '.$symbol.number_format($charge, 2)
            .' · credits €'.number_format($euros, 2)
            .' (rate '.$eurPerUnit.', locked until '.$untilLabel.')';
    }

    public function approvalEmailLine(DepositRequest $deposit, float $receivedCharge, float $creditedEuros): string
    {
        $currency = strtoupper((string) ($deposit->charge_currency ?: 'EUR'));
        $rate = (float) ($deposit->fx_rate ?? 1);
        $eurPerUnit = $rate > 0 ? round(1 / $rate, 4) : 1.0;
        $symbol = $this->money->symbolFor($currency === 'GBP' ? 'GBP' : 'EUR');
        $unit = $currency === 'GBP' ? '£' : '€';

        return 'We received '.$symbol.number_format($receivedCharge, 2)
            .', credited as €'.number_format($creditedEuros, 2)
            .' to your wallet (rate 1 '.$unit.' = €'.$eurPerUnit.').';
    }
}
