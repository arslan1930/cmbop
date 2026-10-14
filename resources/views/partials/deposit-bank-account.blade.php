@php
    $depositPayment = $depositPayment ?? \App\Support\DepositPaymentConfig::depositPayment();
    $showExtended = $showExtended ?? false;
    $iban = $depositPayment['eur_iban'] ?? $depositPayment['iban'] ?? 'GB30 TRWI 2308 0132 9321 94';
    $bic = $depositPayment['eur_bic'] ?? $depositPayment['bic'] ?? 'TRWIGB2LXXX';
    $ukAccount = $depositPayment['uk_account_number'] ?? '32932194';
    $ukSort = $depositPayment['uk_sort_code'] ?? '23-08-01';
    $bankRail = $bankRail ?? app(\App\Support\DisplayCurrencyPreference::class)->payFrom();
    $bankRail = $bankRail === 'gbp' ? 'gbp' : 'eur';
    $showPayFromSwitch = $showPayFromSwitch ?? true;
    $sendLabel = $sendLabel ?? null;
    $fx = app(\App\Support\DepositFx::class);
    $ledgerEuros = (float) ($eurAmount ?? $prefillAmount ?? 0);
    $eurSendLabel = $fx->quote($ledgerEuros, 'EUR')['send_label'];
    $gbpSendLabel = $fx->quote($ledgerEuros, 'GBP')['send_label'];
    $sendLabel = $sendLabel ?: ($bankRail === 'gbp' ? $gbpSendLabel : $eurSendLabel);
    $fxNotes = $fxNotes ?? $fx->customerFxNotes(null, $bankRail === 'gbp' ? 'GBP' : 'EUR');
    $rateLockNote = $rateLockNote ?? ($fxNotes[0] ?? $fx->rateLockNote());
    $showFxNotes = $showFxNotes ?? ($fxNotes !== []);
    $currencyGuideUrl = $currencyGuideUrl ?? \App\Support\DepositPaymentConfig::currencyGuideUrl();
    $currencyGuideLabel = $currencyGuideLabel ?? \App\Support\DepositPaymentConfig::currencyGuideLabel();
    $payFromPersistUrl = $payFromPersistUrl ?? (auth()->check() ? route('advertiser.add-funds.pay-from', absolute: false) : '');
@endphp
@include('partials.usd-bank-wire-note', [
    'walletCurrency' => $walletCurrency ?? null,
    'eurAmount' => $eurAmount ?? null,
    'showMethodSwitch' => $showMethodSwitch ?? true,
    'methodSwitchAsLinks' => $methodSwitchAsLinks ?? false,
    'usdWireUser' => $usdWireUser ?? null,
])
@if($showPayFromSwitch)
    <div class="mb-3"
         data-pay-from-switch="1"
         data-pay-from-current="{{ $bankRail }}"
         data-send-eur="{{ $eurSendLabel }}"
         data-send-gbp="{{ $gbpSendLabel }}"
         data-persist-url="{{ $payFromPersistUrl }}">
        <p style="font-size: 13px; font-weight: 600; color: #1a585e; margin-bottom: 6px;">I'm paying from:</p>
        <div class="d-flex flex-wrap gap-2">
            <button type="button"
                    class="btn btn-sm {{ $bankRail === 'gbp' ? 'btn-primary' : 'btn-outline-secondary' }}"
                    data-pay-from="gbp">UK bank (GBP)</button>
            <button type="button"
                    class="btn btn-sm {{ $bankRail === 'eur' ? 'btn-primary' : 'btn-outline-secondary' }}"
                    data-pay-from="eur">Other (EUR)</button>
        </div>
    </div>
@endif
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
@if($showBeneficiary ?? true)
<div style="margin-bottom: 12px;">
    <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Beneficiary:</p>
    <p style="font-weight: 600; margin: 0;">{{ $depositPayment['beneficiary'] ?? 'Teqno LTD' }}</p>
</div>
@endif
<div style="margin-bottom: 12px;" class="af-bank-account af-bank-account--gbp" data-bank-rail="gbp" @if($bankRail !== 'gbp') hidden @endif>
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
<div style="margin-bottom: 12px;" class="af-bank-account af-bank-account--eur" data-bank-rail="eur" @if($bankRail !== 'eur') hidden @endif>
    <p style="font-size: 13px; font-weight: 600; color: #1a585e; margin-bottom: 6px;">EUR account — send euros (SEPA / from outside the UK)</p>
    <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">IBAN:</p>
    <div id="bankIban" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $iban }}</div>
    @if($showCopy ?? true)
        <button type="button" class="copy-btn mt-1" data-target="bankIban">Copy IBAN</button>
    @endif
    <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px; margin-top: 10px;">Swift/BIC:</p>
    <div id="bankBic" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $bic }}</div>
    @if($showCopy ?? true)
        <button type="button" class="copy-btn mt-1" data-target="bankBic">Copy Swift/BIC</button>
    @endif
    <p class="small text-muted mb-0 mt-1">{{ $depositPayment['intl_transfer_note'] ?? 'Use this EUR account when sending euros, including from outside the UK' }}</p>
</div>
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
<script>
(function () {
    if (window.__slbPayFromBound) {
        return;
    }
    window.__slbPayFromBound = true;

    function csrfToken() {
        var boot = window.AddFundsBoot || {};
        if (boot.csrfToken) {
            return boot.csrfToken;
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function applyPayFrom(rail, persist) {
        rail = rail === 'gbp' ? 'gbp' : 'eur';
        if (window.AddFundsBoot) {
            window.AddFundsBoot.payFrom = rail;
        }
        document.querySelectorAll('[data-pay-from-switch]').forEach(function (box) {
            box.setAttribute('data-pay-from-current', rail);
            box.querySelectorAll('[data-pay-from]').forEach(function (btn) {
                var on = btn.getAttribute('data-pay-from') === rail;
                btn.classList.toggle('btn-primary', on);
                btn.classList.toggle('btn-outline-secondary', !on);
            });
            var label = box.getAttribute(rail === 'gbp' ? 'data-send-gbp' : 'data-send-eur');
            var root = box.closest('.card-body') || box.parentElement || document;
            if (label) {
                (root.querySelectorAll ? root : document).querySelectorAll('[data-deposit-send-label]').forEach(function (el) {
                    el.textContent = label;
                });
            }
        });
        document.querySelectorAll('[data-bank-rail]').forEach(function (el) {
            el.hidden = el.getAttribute('data-bank-rail') !== rail;
        });
        document.querySelectorAll('[data-deposit-fx-notes]').forEach(function (el) {
            var display = String((window.AddFundsBoot && window.AddFundsBoot.displayCurrency) || '').toUpperCase();
            var show = rail === 'gbp' || display === 'USD' || display === 'GBP';
            el.hidden = !show;
            var lock = el.querySelector('[data-deposit-rate-lock]');
            if (lock) {
                lock.textContent = show ? (el.getAttribute('data-rate-lock') || '') : '';
            }
        });
        if (typeof window.slbSyncBankSendLabel === 'function') {
            window.slbSyncBankSendLabel();
        }
        if (!persist) {
            return;
        }
        var box = document.querySelector('[data-pay-from-switch][data-persist-url]');
        var url = box ? box.getAttribute('data-persist-url') : '';
        if (!url) {
            return;
        }
        var boot = window.AddFundsBoot || {};
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken()
            },
            body: JSON.stringify({
                pay_from: rail,
                deposit_id: boot.depositId || null
            })
        }).catch(function () {});
    }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-pay-from-switch] [data-pay-from]');
        if (!btn) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        applyPayFrom(btn.getAttribute('data-pay-from'), true);
    });
})();
</script>
