@php
    $compact = $compact ?? false;
    $showMethods = $showMethods ?? true;
@endphp
<div class="payment-trust {{ $compact ? 'payment-trust--compact' : '' }}" role="note" aria-label="Secure payments">
    <div class="payment-trust__secure">
        <i class="fas fa-lock" aria-hidden="true"></i>
        <span>Payments secured by <strong>Stripe</strong>. Card details never touch our servers.</span>
    </div>
    @if($showMethods)
        <div class="payment-trust__methods" aria-label="Accepted payment methods">
            <i class="fab fa-cc-visa" title="Visa" aria-hidden="true"></i>
            <i class="fab fa-cc-mastercard" title="Mastercard" aria-hidden="true"></i>
            <i class="fab fa-cc-amex" title="American Express" aria-hidden="true"></i>
            <span class="payment-trust__pill" title="Wise">Wise</span>
            <span class="payment-trust__pill" title="Bank transfer">Bank</span>
        </div>
    @endif
</div>

@once
    <style>
        .payment-trust {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px 16px;
            font-size: 12px;
            color: #4b5563;
        }
        .payment-trust__secure {
            display: flex;
            align-items: center;
            gap: 8px;
            line-height: 1.35;
        }
        .payment-trust__secure .fa-lock {
            color: #0b6266;
            flex-shrink: 0;
        }
        .payment-trust__methods {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #6b7280;
            font-size: 22px;
        }
        .payment-trust__pill {
            font-size: 11px;
            font-weight: 600;
            color: #374151;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 2px 8px;
            line-height: 1.4;
        }
        .payment-trust--compact .payment-trust__methods {
            font-size: 18px;
        }
        .payment-trust--compact {
            font-size: 11px;
        }
    </style>
@endonce
