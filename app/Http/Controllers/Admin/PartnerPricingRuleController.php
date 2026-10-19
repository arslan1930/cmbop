<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerPricingRule;
use App\Services\ActivityLogger;
use App\Services\Partners\PartnerPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PartnerPricingRuleController extends Controller
{
    public function __construct(private PartnerPricingService $pricing) {}

    public function index(): View
    {
        $rules = PartnerPricingRule::query()->orderByDesc('is_default')->orderBy('name')->get();

        return view('admin.partners.pricing-rules', compact('rules'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $rule = PartnerPricingRule::query()->create($data + ['created_by' => $request->user()->id]);
        $this->maybeDefault($rule, $request->boolean('is_default'));
        ActivityLogger::tryLog('partner.pricing_rule.created', 'Created pricing rule '.$rule->name, $rule, [], null, $request->user());

        return back()->with('success', 'Pricing rule saved.');
    }

    public function update(Request $request, PartnerPricingRule $pricing_rule): RedirectResponse
    {
        $data = $this->validated($request);
        $pricing_rule->fill($data);
        $pricing_rule->save();
        $this->maybeDefault($pricing_rule, $request->boolean('is_default'));
        ActivityLogger::tryLog('partner.pricing_rule.updated', 'Updated pricing rule '.$pricing_rule->name, $pricing_rule, [], null, $request->user());

        return back()->with('success', 'Pricing rule updated.');
    }

    public function preview(Request $request): View
    {
        $partnerEur = (float) $request->input('partner_eur', 40);
        $rule = $request->filled('pricing_rule_id')
            ? PartnerPricingRule::query()->find($request->input('pricing_rule_id'))
            : $this->pricing->defaultRule();
        $quote = $this->pricing->quote($partnerEur, $rule, 0);

        return view('admin.partners.pricing-preview', compact('partnerEur', 'rule', 'quote'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'min_commission_eur' => 'required|numeric|min:0|max:10000',
            'rounding' => 'required|in:end_9,end_0,none',
            'jitter_percent' => 'required|numeric|min:0|max:5',
            'active' => 'nullable|boolean',
            'tiers_json' => 'nullable|string|max:5000',
        ]);
        $tiers = json_decode((string) ($data['tiers_json'] ?? ''), true);
        if (! is_array($tiers) || $tiers === []) {
            $tiers = (new PartnerPricingRule)->normalizedTiers();
        }

        return [
            'name' => $data['name'],
            'min_commission_eur' => $data['min_commission_eur'],
            'rounding' => $data['rounding'],
            'jitter_percent' => $data['jitter_percent'],
            'active' => $request->boolean('active', true),
            'tiers' => $tiers,
        ];
    }

    private function maybeDefault(PartnerPricingRule $rule, bool $makeDefault): void
    {
        if (! $makeDefault) {
            return;
        }
        PartnerPricingRule::query()->where('id', '!=', $rule->id)->update(['is_default' => false]);
        $rule->is_default = true;
        $rule->save();
    }
}
