@php
    $depositPayment = $depositPayment ?? \App\Support\DepositPaymentConfig::depositPayment();
    $showExtended = $showExtended ?? false;
    $iban = $depositPayment['eur_iban'] ?? \App\Support\DepositPaymentConfig::EUR_IBAN_DEFAULT;
    $bic = $depositPayment['eur_bic'] ?? \App\Support\DepositPaymentConfig::EUR_BIC_DEFAULT;
    $beneficiaryDisplay = $depositPayment['beneficiary_display']
        ?? \App\Support\DepositPaymentConfig::beneficiaryDisplayName($depositPayment);
    $ukAccount = $depositPayment['uk_account_number'] ?? '32932194';
    $ukSort = $depositPayment['uk_sort_code'] ?? '23-08-01';
    $lockedUser = auth()->user();
    $lockedCurrency = \App\Support\AccountCurrency::forUser($lockedUser)
        ?? app(\App\Support\ViewerCountry::class)->displayCurrency();
    $bankRail = \App\Support\AccountCurrency::bankRailForCurrency($lockedCurrency)
        ?? (($bankRail ?? 'eur') === 'gbp' ? 'gbp' : 'eur');
    $sendLabel = $sendLabel ?? null;
    $fx = app(\App\Support\DepositFx::class);
    $ledgerEuros = (float) ($eurAmount ?? $prefillAmount ?? 0);
    $chargeCurrency = $bankRail === 'gbp' ? 'GBP' : 'EUR';
    $sendLabel = $sendLabel ?: $fx->quote($ledgerEuros, $chargeCurrency)['send_label'];
    $fxNotes = $fxNotes ?? $fx->customerFxNotes(null, $chargeCurrency);
    $rateLockNote = $rateLockNote ?? ($fxNotes[0] ?? $fx->rateLockNote());
    $showFxNotes = $showFxNotes ?? ($fxNotes !== []);
    $currencyGuideUrl = $currencyGuideUrl ?? \App\Support\DepositPaymentConfig::currencyGuideUrl();
    $currencyGuideLabel = $currencyGuideLabel ?? \App\Support\DepositPaymentConfig::currencyGuideLabel();
    $paymentReference = $paymentReference
        ?? (isset($deposit) && $deposit ? $deposit->paymentReferenceLabel() : null);
@endphp
@include('partials.usd-bank-wire-note', [
    'walletCurrency' => $walletCurrency ?? null,
    'eurAmount' => $eurAmount ?? null,
    'showMethodSwitch' => $showMethodSwitch ?? true,
    'methodSwitchAsLinks' => $methodSwitchAsLinks ?? false,
    'usdWireUser' => $usdWireUser ?? null,
])
@if($sendLabel)
    <p class="mb-1" data-deposit-send-label="1" style="font-size: 13px; font-weight: 600; color: #1a585e;">{{ $sendLabel }}</p>
@endif
<div data-deposit-fx-notes="1"
     data-rate-lock="{{ $fx->rateLockNote() }}"
     class="mb-1"
     @if(! $showFxNotes) hidden @endif>
    <p class="mb-1 small text-muted" data-deposit-rate-lock="1">{{ $showFxNotes ? $rateLockNote : '' }}</p>
</div>
<p class="mb-3 small" data-currency-guide="1">
    <a href="{{ $currencyGuideUrl }}">{{ $currencyGuideLabel }}</a>
</p>
<div style="margin-bottom: 12px;" data-deposit-payment-reference="1" @if(! $paymentReference) hidden @endif>
    <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Payment reference:</p>
    <div id="bankPaymentReference" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $paymentReference ?: '' }}</div>
    @if($showCopy ?? true)
        <button type="button" class="copy-btn mt-1" data-target="bankPaymentReference">Copy reference</button>
    @endif
    <p class="small text-muted mb-0 mt-1">Put this reference in the payment reference so we can match your transfer.</p>
</div>
@if($showBeneficiary ?? true)
<div style="margin-bottom: 12px;">
    <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Beneficiary:</p>
    <p style="font-weight: 600; margin: 0;">{{ $beneficiaryDisplay }}</p>
</div>
@endif
@if($bankRail === 'gbp')
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
@else
<div style="margin-bottom: 12px;" class="af-bank-account af-bank-account--eur" data-bank-rail="eur">
    <p style="font-size: 13px; font-weight: 600; color: #1a585e; margin-bottom: 6px;">EUR account</p>
    <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">IBAN:</p>
    <div id="bankIban" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $iban }}</div>
    @if($showCopy ?? true)
        <button type="button" class="copy-btn mt-1" data-target="bankIban">Copy IBAN</button>
    @endif
    <p class="small text-muted mb-0 mt-1">{{ $depositPayment['intl_transfer_note'] ?? \App\Support\DepositPaymentConfig::EUR_SEPA_NOTE }}</p>
    <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px; margin-top: 10px;">{{ \App\Support\DepositPaymentConfig::EUR_BIC_NOTE }}</p>
    <div id="bankBic" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $bic }}</div>
    @if($showCopy ?? true)
        <button type="button" class="copy-btn mt-1" data-target="bankBic">Copy Swift/BIC</button>
    @endif
</div>
@endif
@if($showExtended)
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
