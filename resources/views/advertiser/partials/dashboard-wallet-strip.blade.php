@php
    $walletBonus = (float) ($wallet['bonus'] ?? 0);
    $showBonus = $walletBonus > 0.009;
@endphp
<div class="dash-wallet-strip">
    <div class="dw-item">
        <span class="dw-label">Spendable</span>
        <div class="dw-value">€{{ number_format((float) ($wallet['spendable'] ?? 0), 2) }}</div>
    </div>
    @if($showBonus)
        <div class="dw-item">
            <span class="dw-label">Bonus</span>
            <div class="dw-value">€{{ number_format($walletBonus, 2) }}</div>
        </div>
    @endif
    <div class="dw-item d-flex align-items-center">
        <a href="{{ route('advertiser.add-funds') }}" class="btn btn-sm btn-primary">
            @if(!empty($budgetStatus['low_balance']))
                Top up — low balance
            @else
                Add funds
            @endif
        </a>
    </div>
    @if($showBonus)
        <p class="dw-bonus-note">
            €{{ number_format($walletBonus, 2) }} welcome bonus included in Spendable
        </p>
    @endif
    @if(!empty($budgetStatus['low_balance']))
        <p class="dw-warn">
            Spendable is below your €{{ number_format((float) ($budgetStatus['low_balance_threshold'] ?? 0), 2) }} alert threshold.
        </p>
    @elseif(!empty($budgetStatus['monthly_limit']))
        <p class="dw-warn" style="background:#f0fbfb;border-color:#b8e4e4;color:#1a585e;">
            This month committed €{{ number_format((float) ($budgetStatus['committed'] ?? 0), 2) }}
            / €{{ number_format((float) $budgetStatus['monthly_limit'], 2) }}
            ({{ number_format((float) ($budgetStatus['percent'] ?? 0), 1) }}%)
        </p>
    @endif
</div>
