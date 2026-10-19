<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerAutoApproveRule;
use App\Models\PartnerPricingRule;
use App\Services\ActivityLogger;
use App\Services\Partners\PartnerAutoApproveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PartnerAutoApproveRuleController extends Controller
{
    public function __construct(private PartnerAutoApproveService $rules) {}

    public function index(Request $request): View
    {
        $rules = PartnerAutoApproveRule::query()->orderBy('priority')->orderBy('id')->get();
        $partners = Partner::query()->with('user')->orderByDesc('id')->get();
        $pricingRules = PartnerPricingRule::query()->where('active', true)->orderBy('name')->get();
        $dryRun = null;
        if ($request->filled('dry_run')) {
            $rule = PartnerAutoApproveRule::query()->find($request->input('dry_run'));
            if ($rule) {
                $dryRun = ['rule' => $rule] + $this->rules->dryRun($rule);
            }
        }

        return view('admin.partners.auto-approve-rules', compact('rules', 'partners', 'pricingRules', 'dryRun'));
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = PartnerAutoApproveRule::query()->create($this->validated($request) + ['created_by' => $request->user()->id]);
        ActivityLogger::tryLog('partner.auto_approve.created', 'Created auto-approve rule '.$rule->name, $rule, [], null, $request->user());

        return back()->with('success', 'Rule saved. It only runs on newly imported unused sites.');
    }

    public function update(Request $request, PartnerAutoApproveRule $auto_approve_rule): RedirectResponse
    {
        $auto_approve_rule->fill($this->validated($request));
        $auto_approve_rule->save();
        ActivityLogger::tryLog('partner.auto_approve.updated', 'Updated auto-approve rule '.$auto_approve_rule->name, $auto_approve_rule, [], null, $request->user());

        return back()->with('success', 'Rule updated.');
    }

    public function dryRun(Request $request, PartnerAutoApproveRule $auto_approve_rule): RedirectResponse
    {
        return redirect()->route('admin.partners.auto-approve-rules.index', ['dry_run' => $auto_approve_rule->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'priority' => 'required|integer|min:1|max:9999',
            'partner_id' => 'nullable|integer',
            'min_dr' => 'nullable|integer|min:0|max:100',
            'min_da' => 'nullable|integer|min:0|max:100',
            'min_monthly_traffic' => 'nullable|integer|min:0',
            'max_partner_rate_eur' => 'nullable|numeric|min:0',
            'action' => 'required|in:send_to_review,go_live',
            'pricing_rule_id' => 'nullable|integer',
            'require_sample_post' => 'nullable|boolean',
            'reject_sensitive_only' => 'nullable|boolean',
            'active' => 'nullable|boolean',
            'link_types' => 'nullable|array',
            'link_types.*' => 'in:dofollow,nofollow',
        ]);
        $action = $data['action'];
        if ($action === PartnerAutoApproveRule::ACTION_LIVE) {
            // Non-trusted partners still land in review; trusted may go live.
        }

        return [
            'name' => $data['name'],
            'priority' => $data['priority'],
            'partner_id' => $data['partner_id'] ?: null,
            'min_dr' => $data['min_dr'] ?? null,
            'min_da' => $data['min_da'] ?? null,
            'min_monthly_traffic' => $data['min_monthly_traffic'] ?? null,
            'max_partner_rate_eur' => $data['max_partner_rate_eur'] ?? null,
            'action' => $action,
            'pricing_rule_id' => $data['pricing_rule_id'] ?? null,
            'require_sample_post' => $request->boolean('require_sample_post', true),
            'reject_sensitive_only' => $request->boolean('reject_sensitive_only', true),
            'active' => $request->boolean('active', true),
            'link_types' => array_values($data['link_types'] ?? ['dofollow']),
        ];
    }
}
