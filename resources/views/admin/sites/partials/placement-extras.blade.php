@php
    $editable = $editable ?? true;
    $layout = $layout ?? 'inset';
    $site = $site ?? null;
    $homepageDays = config('site_placement.homepage_days', [1, 7, 30]);
    $sensitiveTopics = ['crypto', 'trading', 'CBD', 'forex'];
    $socialChannels = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X'];
    $existingHomepage = is_array($site?->homepage_placement_prices) ? $site->homepage_placement_prices : [];
    $existingSocial = is_array($site?->social_promotion) ? $site->social_promotion : [];
    $existingSensitive = is_array($site?->sensitive_prices) ? $site->sensitive_prices : [];
    $hasOld = session()->hasOldInput();

    $homepageChecked = function (int $days) use ($hasOld, $existingHomepage): bool {
        if ($hasOld) {
            return (bool) old('homepage.'.$days);
        }

        return array_key_exists((string) $days, $existingHomepage) || array_key_exists($days, $existingHomepage);
    };
    $socialChecked = function (string $channel) use ($hasOld, $existingSocial): bool {
        if ($hasOld) {
            return (bool) old('social.'.$channel);
        }

        return ! empty($existingSocial[$channel]);
    };
    $sensitiveChecked = function (string $topic) use ($hasOld, $existingSensitive): bool {
        if ($hasOld) {
            return (bool) old('sensitive.'.$topic);
        }

        return array_key_exists($topic, $existingSensitive);
    };

    $hasSensitiveOpen = false;
    foreach ($sensitiveTopics as $topic) {
        if ($hasOld) {
            $flag = old("sensitive.$topic");
            $price = old("price_sensitive.$topic");
            if (($flag !== null && $flag !== '' && $flag !== [])
                || ($price !== null && $price !== '' && $price !== [])) {
                $hasSensitiveOpen = true;
                break;
            }
        } elseif (array_key_exists($topic, $existingSensitive)) {
            $hasSensitiveOpen = true;
            break;
        }
    }

    $lockedHomepage = $site instanceof \App\Models\Site ? $site->homepagePlacementOptions() : [];
    $lockedSocial = $site instanceof \App\Models\Site ? $site->enabledSocialChannels() : [];
    $lockedSensitive = [];
    foreach ($existingSensitive as $topic => $fee) {
        if (! is_numeric($fee)) {
            continue;
        }
        $lockedSensitive[(string) $topic] = round((float) $fee, 2);
    }
    $hasAnyLockedExtras = $lockedHomepage !== [] || $lockedSocial !== [] || $lockedSensitive !== [];
@endphp

@if($editable)
    @if($layout === 'card')
        <div class="card border-0 shadow-sm mb-3 staff-assign-site-section">
            <div class="card-body">
    @else
        <div class="col-12">
            <div class="border rounded p-3 bg-light">
    @endif
                <h5 class="fw-semibold mb-1">Placement extras</h5>
                <p class="small text-muted mb-3">Shown in catalog Site Details. Leave unchecked to hide the offer. Fee 0 = free (homepage) or no extra (sensitive topics). Social sharing is always free.</p>
                <input type="hidden" name="placement_offers_form" value="1">
                <p class="fw-semibold small mb-2">Homepage placement</p>
                <div class="d-flex flex-wrap gap-3 mb-3">
                    @foreach($homepageDays as $days)
                        @php
                            $priceVal = old_text('price_homepage.'.$days, $existingHomepage[(string) $days] ?? $existingHomepage[$days] ?? '');
                        @endphp
                        <div style="min-width:140px;">
                            <div class="form-check">
                                <input type="checkbox" name="homepage[{{ $days }}]" value="1"
                                       class="form-check-input staff-assign-fee-toggle" id="staffHomepage{{ $days }}"
                                       data-fee-input="price_homepage[{{ $days }}]"
                                       {{ $homepageChecked((int) $days) ? 'checked' : '' }}>
                                <label class="form-check-label" for="staffHomepage{{ $days }}">{{ $days }} day{{ $days > 1 ? 's' : '' }}</label>
                            </div>
                            <input type="number" name="price_homepage[{{ $days }}]" class="form-control mt-1 @error('price_homepage.'.$days) is-invalid @enderror"
                                   placeholder="Fee (€) — 0 = Free" min="0" step="0.01" inputmode="decimal"
                                   value="{{ $priceVal }}">
                            @error('price_homepage.'.$days)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                </div>
                <p class="fw-semibold small mb-2">Social media sharing (always free)</p>
                <div class="d-flex flex-wrap gap-3 mb-3">
                    @foreach($socialChannels as $channel => $label)
                        <div class="form-check">
                            <input type="checkbox" name="social[{{ $channel }}]" value="1"
                                   class="form-check-input" id="staffSocial{{ ucfirst($channel) }}"
                                   {{ $socialChecked($channel) ? 'checked' : '' }}>
                            <label class="form-check-label" for="staffSocial{{ ucfirst($channel) }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
                <button type="button"
                        class="disclosure-toggle"
                        id="sensitiveDisclosureBtn"
                        aria-expanded="{{ $hasSensitiveOpen ? 'true' : 'false' }}"
                        aria-controls="sensitiveDisclosurePanel">
                    <i class="fa fa-chevron-{{ $hasSensitiveOpen ? 'down' : 'right' }}" aria-hidden="true"></i>
                    Sensitive topics (optional)
                </button>
                <p class="small text-muted mb-0 mt-1">Only open if this publisher accepts crypto, trading, CBD, or forex. Checked + blank extra fills as €0.</p>
                <div class="disclosure-panel" id="sensitiveDisclosurePanel" @unless($hasSensitiveOpen) hidden @endunless>
                    <div class="row bg-light p-3 rounded mt-2">
                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-3">
                                @foreach($sensitiveTopics as $topic)
                                    @php
                                        $sensitivePriceVal = old_text('price_sensitive.'.$topic, $existingSensitive[$topic] ?? '');
                                    @endphp
                                    <div class="me-3">
                                        <div class="form-check">
                                            <input type="checkbox" name="sensitive[{{ $topic }}]" value="1"
                                                   class="form-check-input staff-assign-fee-toggle" id="sensitive{{ $topic }}"
                                                   data-fee-input="price_sensitive[{{ $topic }}]"
                                                   {{ $sensitiveChecked($topic) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="sensitive{{ $topic }}">{{ ucfirst($topic) }}</label>
                                        </div>
                                        <input type="number" name="price_sensitive[{{ $topic }}]" class="form-control mt-1 @error('price_sensitive.'.$topic) is-invalid @enderror" placeholder="Extra (€) — 0 = none" value="{{ $sensitivePriceVal }}" min="0" step="0.01">
                                        @error('price_sensitive.'.$topic)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
    @if($layout === 'card')
            </div>
        </div>
    @else
            </div>
        </div>
    @endif
@elseif($hasAnyLockedExtras)
    <div class="col-12">
        <div class="border rounded p-3 bg-light">
            <p class="fw-semibold mb-1">Placement extras</p>
            <p class="small text-muted mb-2">Locked on live listings. Ask an admin to change these.</p>
            @if($lockedHomepage !== [])
                <p class="small mb-1"><span class="fw-semibold">Homepage:</span>
                    @foreach($lockedHomepage as $days => $fee)
                        {{ $days }} day{{ (int) $days > 1 ? 's' : '' }} {{ format_money($fee) }}@if(! $loop->last), @endif
                    @endforeach
                </p>
            @endif
            @if($lockedSocial !== [])
                <p class="small mb-1"><span class="fw-semibold">Social:</span>
                    {{ collect($lockedSocial)->map(fn ($c) => ucfirst((string) $c))->implode(', ') }}
                </p>
            @endif
            @if($lockedSensitive !== [])
                <p class="small mb-0"><span class="fw-semibold">Sensitive topics:</span>
                    @foreach($lockedSensitive as $topic => $fee)
                        {{ ucfirst((string) $topic) }} +{{ format_money($fee) }}@if(! $loop->last), @endif
                    @endforeach
                </p>
            @endif
        </div>
    </div>
@endif

@once
@push('scripts')
<script>
(function () {
    document.querySelectorAll('.staff-assign-fee-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (!cb.checked) return;
            const name = cb.getAttribute('data-fee-input');
            if (!name) return;
            const input = document.querySelector('input[name="' + name.replace(/"/g, '') + '"]');
            if (input && String(input.value || '').trim() === '') input.value = '0';
        });
    });

    const sensitiveBtn = document.getElementById('sensitiveDisclosureBtn');
    const sensitivePanel = document.getElementById('sensitiveDisclosurePanel');
    if (sensitiveBtn && sensitivePanel) {
        sensitiveBtn.addEventListener('click', function () {
            const open = sensitivePanel.hasAttribute('hidden');
            if (open) {
                sensitivePanel.removeAttribute('hidden');
            } else {
                sensitivePanel.setAttribute('hidden', '');
            }
            sensitiveBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            const icon = sensitiveBtn.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-chevron-right', !open);
                icon.classList.toggle('fa-chevron-down', open);
            }
        });
    }
})();
</script>
@endpush
@endonce
