@php
    $depositPayment = $depositPayment ?? config('billing.deposit_payment', []);
    $showExtended = $showExtended ?? false;
    $iban = $depositPayment['iban'] ?? 'GB30 TRWI 2308 0132 9321 94';
    $bic = $depositPayment['bic'] ?? 'TRWIGB2LXXX';
    $ukAccount = $depositPayment['uk_account_number'] ?? '32932194';
    $ukSort = $depositPayment['uk_sort_code'] ?? '23-08-01';
    $bankRail = $bankRail ?? app(\App\Support\DisplayCurrencyPreference::class)->payFrom();
    $bankRail = $bankRail === 'gbp' ? 'gbp' : 'eur';
    $showPayFromSwitch = $showPayFromSwitch ?? true;
    $payFromAction = $payFromAction ?? url()->current();
    $sendLabel = $sendLabel ?? null;
@endphp
@include('partials.usd-bank-wire-note', [
    'walletCurrency' => $walletCurrency ?? null,
    'eurAmount' => $eurAmount ?? null,
    'showMethodSwitch' => $showMethodSwitch ?? true,
    'methodSwitchAsLinks' => $methodSwitchAsLinks ?? false,
    'usdWireUser' => $usdWireUser ?? null,
])
@if($showPayFromSwitch)
    <div class="mb-3" data-pay-from-switch="1">
        <p style="font-size: 13px; font-weight: 600; color: #1a585e; margin-bottom: 6px;">I'm paying from:</p>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ request()->fullUrlWithQuery(['pay_from' => 'gbp']) }}"
               class="btn btn-sm {{ $bankRail === 'gbp' ? 'btn-primary' : 'btn-outline-secondary' }}"
               data-pay-from="gbp">UK bank (GBP)</a>
            <a href="{{ request()->fullUrlWithQuery(['pay_from' => 'eur']) }}"
               class="btn btn-sm {{ $bankRail === 'eur' ? 'btn-primary' : 'btn-outline-secondary' }}"
               data-pay-from="eur">Other (EUR)</a>
        </div>
    </div>
@endif
@if($sendLabel)
    <p class="mb-3" data-deposit-send-label="1" style="font-size: 13px; font-weight: 600; color: #1a585e;">{{ $sendLabel }}</p>
@endif
@if($showBeneficiary ?? true)
<div style="margin-bottom: 12px;">
    <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Beneficiary:</p>
    <p style="font-weight: 600; margin: 0;">{{ $depositPayment['beneficiary'] ?? 'Teqno LTD' }}</p>
</div>
@endif
@if($bankRail === 'gbp' && ($ukAccount !== '' || $ukSort !== ''))
    <div style="margin-bottom: 12px;" class="af-bank-account af-bank-account--gbp" data-bank-rail="gbp">
        <p style="font-size: 13px; font-weight: 600; color: #1a585e; margin-bottom: 6px;">GBP account — send pounds from a UK bank</p>
        @if($ukSort !== '')
            <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Sort code:</p>
            <div id="bankUkSort" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $ukSort }}</div>
            @if($showCopy ?? true)
                <button type="button" class="copy-btn mt-1" data-target="bankUkSort">Copy sort code</button>
            @endif
        @endif
        @if($ukAccount !== '')
            <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px; {{ $ukSort !== '' ? 'margin-top: 10px;' : '' }}">Account number:</p>
            <div id="bankUkAccount" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $ukAccount }}</div>
            @if($showCopy ?? true)
                <button type="button" class="copy-btn mt-1" data-target="bankUkAccount">Copy account number</button>
            @endif
        @endif
        <p class="small text-muted mb-0 mt-1">{{ $depositPayment['uk_transfer_note'] ?? 'Use this GBP account when sending pounds from a UK bank' }}</p>
    </div>
@endif
@if($bankRail === 'eur')
<div style="margin-bottom: 12px;" class="af-bank-account af-bank-account--eur" data-bank-rail="eur">
    <p style="font-size: 13px; font-weight: 600; color: #1a585e; margin-bottom: 6px;">EUR account — send euros (SEPA / from outside the UK)</p>
    <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">IBAN:</p>
    <div id="bankIban" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $iban }}</div>
    <p class="small text-muted mb-0 mt-1">{{ $depositPayment['intl_transfer_note'] ?? 'Use this EUR account when sending euros, including from outside the UK' }}</p>
    @if($showCopy ?? true)
        <button type="button" class="copy-btn mt-1" data-target="bankIban">Copy IBAN</button>
    @endif
</div>
<div style="margin-bottom: 12px;" data-bank-rail="eur">
    <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Swift/BIC:</p>
    <div id="bankBic" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $bic }}</div>
    <p class="small text-muted mb-0 mt-1">{{ $depositPayment['intl_transfer_note'] ?? 'Use this EUR account when sending euros, including from outside the UK' }}</p>
    @if($showCopy ?? true)
        <button type="button" class="copy-btn mt-1" data-target="bankBic">Copy Swift/BIC</button>
    @endif
</div>
@endif
@if($showExtended)
    @if(!empty($depositPayment['phone']))
        <div style="margin-bottom: 12px;">
            <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Phone no:</p>
            <p style="font-weight: 600; margin: 0;">{{ $depositPayment['phone'] }}</p>
        </div>
    @endif
    @foreach(($depositPayment['address_lines'] ?? []) as $line)
        <div style="margin-bottom: 12px;">
            @if($loop->first)
                <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Address:</p>
            @endif
            <p style="font-weight: 600; margin: 0;">{{ $line }}</p>
        </div>
    @endforeach
    @if(!empty($depositPayment['registration_no']))
        <div style="margin-bottom: 12px;">
            <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Registration No:</p>
            <p style="font-weight: 600; margin: 0;">{{ $depositPayment['registration_no'] }}</p>
        </div>
    @endif
@endif
