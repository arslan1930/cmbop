@php
    $addons = $checkoutAddOns ?? app(\App\Services\Marketplace\CheckoutAddOnService::class);
    $guaranteePercent = $addons->guaranteePercent();
    $guaranteeTerms = $addons->termsText();
@endphp

<div class="card border-0 shadow-sm mb-4" id="checkoutAddOns">
    <div class="card-header bg-white fw-semibold">
        Article source &amp; link guarantee
    </div>
    <div class="card-body">
        @foreach($cartItems as $item)
            @if(empty($item['paying_now']))
                @continue
            @endif
            @php
                $offered = !empty($item['writing_offered']);
                $fee = (float) ($item['writing_fee'] ?? 0);
            @endphp
            <div class="border rounded p-3 mb-3 checkout-writing-line"
                 data-site-id="{{ $item['id'] }}"
                 data-writing-offered="{{ $offered ? '1' : '0' }}"
                 data-writing-fee="{{ $fee }}"
                 data-base-total="{{ (float) ($item['total'] ?? $item['price'] ?? 0) }}">
                <div class="fw-semibold mb-2">{{ $item['name'] }}</div>
                <div class="form-check">
                    <input class="form-check-input checkout-content-source" type="radio"
                           name="content_source[{{ $item['id'] }}]" value="buyer"
                           id="contentSourceBuyer{{ $item['id'] }}" checked>
                    <label class="form-check-label" for="contentSourceBuyer{{ $item['id'] }}">
                        I supply the article
                        <span class="text-muted">(listing price)</span>
                    </label>
                </div>
                @if($offered)
                    <div class="form-check">
                        <input class="form-check-input checkout-content-source" type="radio"
                               name="content_source[{{ $item['id'] }}]" value="publisher"
                               id="contentSourcePublisher{{ $item['id'] }}">
                        <label class="form-check-label" for="contentSourcePublisher{{ $item['id'] }}">
                            Publisher writes the article
                            @if($fee > 0)
                                <span class="text-muted">(+ {{ format_money_pay($fee) }} writing fee)</span>
                            @endif
                        </label>
                    </div>
                    <div class="checkout-writing-brief d-none mt-2">
                        <label class="form-label small" for="writingTarget{{ $item['id'] }}">Target URL for the publisher-written article</label>
                        <input type="url" class="form-control form-control-sm checkout-writing-target"
                               name="writing_brief[{{ $item['id'] }}][target_url]"
                               id="writingTarget{{ $item['id'] }}"
                               placeholder="https://">
                        <label class="form-label small mt-2" for="writingAnchor{{ $item['id'] }}">Preferred anchor text (optional)</label>
                        <input type="text" class="form-control form-control-sm"
                               name="writing_brief[{{ $item['id'] }}][anchor_text]"
                               id="writingAnchor{{ $item['id'] }}"
                               maxlength="160">
                    </div>
                @endif
            </div>
        @endforeach

        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" value="1" id="linkGuarantee" name="link_guarantee">
            <label class="form-check-label" for="linkGuarantee">
                Add a 12-month link guarantee
                <span class="text-muted">({{ rtrim(rtrim(number_format($guaranteePercent, 2), '0'), '.') }}% of the placement total)</span>
            </label>
        </div>
        <p class="small text-muted mb-0 mt-2" id="linkGuaranteeTerms">{{ $guaranteeTerms }}</p>
    </div>
</div>
