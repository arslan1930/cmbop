@php
    $payoutRules = $payoutRules ?? [];
    $minAmount = (float) ($payoutRules['min_amount'] ?? 20);
    $feePercent = (float) ($payoutRules['fee_percent'] ?? 0);
    $minMax = (float) ($payoutRules['min_amount_max'] ?? 10000);
    $feeMax = (float) ($payoutRules['fee_percent_max'] ?? 50);
    $tableReady = (bool) ($payoutRules['table_ready'] ?? false);
    $minSource = ($payoutRules['min_amount_source'] ?? 'config') === 'stored' ? 'Admin' : 'Config fallback';
    $feeSource = ($payoutRules['fee_percent_source'] ?? 'config') === 'stored' ? 'Admin' : 'Config fallback';
    $minEuro = '€'.number_format($minAmount, 2);
    $feeLabel = (rtrim(rtrim(number_format($feePercent, 2, '.', ''), '0'), '.') ?: '0').'%';
@endphp
<div id="payout-rules" class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h2 class="h5 mb-0">Payout rules</h2>
                    @if(! $tableReady)
                        <span class="badge bg-warning text-dark">Storage missing</span>
                    @endif
                </div>
                <p class="text-muted small mb-0">
                    Publisher minimum and platform fee on <strong>new</strong> withdrawal requests.
                    Existing requests keep the fee stored when they were submitted.
                    Advertiser leftover withdrawals are not limited by this floor.
                </p>
            </div>
            @if($tableReady)
                <div class="d-flex flex-wrap gap-3 align-items-start">
                    <form method="POST" action="{{ route('admin.finance.payout-rules.min') }}" class="d-flex flex-wrap gap-2 align-items-end"
                          data-slb-confirm="Set the publisher minimum withdrawal? This applies to new payout requests. Existing requests stay."
                          data-slb-confirm-title="Update minimum withdrawal?"
                          data-slb-confirm-text="Save minimum">
                        @csrf
                        <div>
                            <label class="form-label small text-muted mb-1" for="payoutRuleMinAmount">Minimum (€)</label>
                            <input type="number" name="min_amount" id="payoutRuleMinAmount" class="form-control form-control-sm" style="width:7.5rem"
                                   min="0.01" max="{{ $minMax }}" step="0.01" value="{{ number_format($minAmount, 2, '.', '') }}"
                                   required aria-describedby="payoutRuleMinHint">
                            <div id="payoutRuleMinHint" class="form-text">{{ $minEuro }} · {{ $minSource }}</div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Save minimum</button>
                    </form>
                    <form method="POST" action="{{ route('admin.finance.payout-rules.fee') }}" class="d-flex flex-wrap gap-2 align-items-end"
                          data-slb-confirm="Set the withdrawal fee percent? Applies only to new requests. Existing withdrawals keep the fee already stored."
                          data-slb-confirm-title="Update withdrawal fee?"
                          data-slb-confirm-text="Save fee">
                        @csrf
                        <div>
                            <label class="form-label small text-muted mb-1" for="payoutRuleFeePercent">Fee (%)</label>
                            <input type="number" name="fee_percent" id="payoutRuleFeePercent" class="form-control form-control-sm" style="width:7.5rem"
                                   min="0" max="{{ $feeMax }}" step="0.01" value="{{ number_format($feePercent, 2, '.', '') }}"
                                   required aria-describedby="payoutRuleFeeHint">
                            <div id="payoutRuleFeeHint" class="form-text">{{ $feeLabel }} · {{ $feeSource }} · max {{ rtrim(rtrim(number_format($feeMax, 2, '.', ''), '0'), '.') }}%</div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Save fee</button>
                    </form>
                </div>
            @else
                <p class="small text-danger mb-0">Run <code>php artisan ops:production-ready --repair</code> (or migrate) before changing these.</p>
            @endif
        </div>
    </div>
</div>
