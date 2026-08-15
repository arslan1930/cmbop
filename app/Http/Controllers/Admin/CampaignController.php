<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailCampaignJob;
use App\Mail\AudienceCampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\ActivityLogger;
use App\Services\AudienceInventoryService;
use App\Support\CampaignHtml;
use App\Support\EmailCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    public function index(AudienceInventoryService $inventory)
    {
        try {
            EmailCampaign::recoverStalled();
        } catch (\Throwable $e) {
            Log::warning('Campaign stall recovery failed', ['error' => $e->getMessage()]);
        }

        $stats = $inventory->stats(includeUnverified: false);
        $campaigns = EmailCampaign::query()
            ->with('creator')
            ->latest('id')
            ->paginate(15);

        $advertisers = $inventory->pickerUsers('advertiser');
        $publishers = $inventory->pickerUsers('publisher');
        $pickerCapped = $inventory->pickerIsCapped('advertiser')
            || $inventory->pickerIsCapped('publisher');

        return view('admin.campaigns.index', compact(
            'stats',
            'campaigns',
            'advertisers',
            'publishers',
            'pickerCapped'
        ));
    }

    public function preview(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'body_html' => ['required', 'string', 'max:20000'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => $this->ctaUrlRules(),
        ]);

        $campaign = new EmailCampaign([
            'subject' => $data['subject'],
            'body_html' => CampaignHtml::sanitize($data['body_html']),
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $this->safeCtaUrl($data['cta_url'] ?? null),
            'audience' => 'selected',
        ]);

        $mailable = new AudienceCampaignMail($campaign, EmailCatalog::previewUser());
        $mailable->skipUserPreference = true;

        return response($mailable->render());
    }

    public function recipientCount(Request $request, AudienceInventoryService $inventory)
    {
        $this->canonicalizeAudienceInput($request);

        $data = $request->validate($this->audienceInputRules());

        $includeUnverified = $request->boolean('include_unverified');
        $ids = $data['user_ids'] ?? [];
        $count = $inventory->count($data['audience'], $ids, $includeUnverified);
        $unverifiedExcluded = 0;
        if (! $includeUnverified) {
            $unverifiedExcluded = max(0, $inventory->count($data['audience'], $ids, true) - $count);
        }

        return response()->json([
            'count' => $count,
            'label' => EmailCampaign::labelForAudience($data['audience']),
            'unverified_excluded' => $unverifiedExcluded,
        ]);
    }

    public function send(Request $request, AudienceInventoryService $inventory)
    {
        $this->canonicalizeAudienceInput($request);

        $data = $request->validate(array_merge($this->audienceInputRules(), [
            'name' => ['nullable', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:180'],
            'body_html' => ['required', 'string', 'max:20000'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => $this->ctaUrlRules(),
            'respect_preferences' => ['boolean'],
        ]));

        if ($data['audience'] === 'selected' && empty($data['user_ids'])) {
            return back()->withInput()->with('error', 'Select at least one user for a custom audience.');
        }

        $bodyHtml = CampaignHtml::sanitize($data['body_html']);
        if (CampaignHtml::isBlank($data['body_html'])) {
            return back()->withInput()->withErrors([
                'body_html' => 'Write a message before sending.',
            ]);
        }

        $includeUnverified = $request->boolean('include_unverified');
        $recipients = $inventory->collectRecipientRows($data['audience'], $data['user_ids'] ?? [], $includeUnverified)
            ->unique('id')
            ->values();
        if ($recipients->isEmpty()) {
            return back()->withInput()->with('error', 'No recipients found for that audience.');
        }

        $respectPrefs = $request->boolean('respect_preferences');
        $count = $recipients->count();

        $campaign = DB::transaction(function () use ($data, $recipients, $count, $respectPrefs, $bodyHtml) {
            $campaign = EmailCampaign::create([
                'name' => ($data['name'] ?? null) ?: $data['subject'],
                'subject' => $data['subject'],
                'body_html' => $bodyHtml,
                'audience' => $data['audience'],
                'selected_user_ids' => $data['audience'] === 'selected'
                    ? $recipients->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                    : null,
                'cta_label' => $data['cta_label'] ?? null,
                'cta_url' => $this->safeCtaUrl($data['cta_url'] ?? null),
                'recipients_count' => $count,
                'sent_count' => 0,
                'skipped_count' => 0,
                'status' => EmailCampaign::STATUS_QUEUED,
                'respect_preferences' => $respectPrefs,
                'created_by' => auth()->id(),
            ]);

            $now = now();
            foreach ($recipients->chunk(200) as $chunk) {
                EmailCampaignRecipient::query()->insert($chunk->map(fn ($user) => [
                    'email_campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'status' => EmailCampaignRecipient::STATUS_PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            return $campaign;
        });

        try {
            ActivityLogger::log(
                'campaign.queued',
                "Queued campaign \"{$campaign->name}\" for {$count} recipient(s).",
                $campaign,
                [
                    'audience' => $campaign->audience,
                    'recipients' => $count,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Campaign activity log failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            SendEmailCampaignJob::dispatch($campaign->id);
        } catch (\Throwable $e) {
            Log::error('Campaign job dispatch failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaign->id)
                ->where('status', EmailCampaignRecipient::STATUS_PENDING)
                ->update([
                    'status' => EmailCampaignRecipient::STATUS_FAILED,
                    'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
                ]);
            $campaign->refresh()->recountRecipientTotals();
            $campaign->update([
                'status' => EmailCampaign::STATUS_FAILED,
                'sent_at' => now(),
            ]);

            return back()->withInput()->with('error', 'Campaign was saved but could not be queued. Try again.');
        }

        return redirect()
            ->route('admin.campaigns.index')
            ->with('success', "Campaign queued for {$count} recipient(s).");
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function audienceInputRules(): array
    {
        return [
            'audience' => ['required', Rule::in(AudienceInventoryService::audienceKeys())],
            'user_ids' => ['nullable', 'array', 'max:'.(AudienceInventoryService::PICKER_LIMIT * 2)],
            'user_ids.*' => ['integer'],
            'include_unverified' => ['boolean'],
        ];
    }

    /**
     * @return list<mixed>
     */
    protected function ctaUrlRules(): array
    {
        return [
            'nullable',
            'string',
            'max:500',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! CampaignHtml::isSafeHttpUrl((string) $value)) {
                    $fail('The CTA URL must be an http or https link.');
                }
            },
        ];
    }

    protected function safeCtaUrl(?string $url): ?string
    {
        if (! filled($url)) {
            return null;
        }

        return CampaignHtml::isSafeHttpUrl($url) ? $url : null;
    }

    /**
     * Inventory tab slugs (no_orders, paid_orders, …) become campaign keys
     * before validation so a bookmark or mistyped form cannot 422 / send empty.
     */
    protected function canonicalizeAudienceInput(Request $request): void
    {
        $raw = $request->input('audience');
        if (! is_string($raw)) {
            return;
        }

        $canonical = AudienceInventoryService::canonicalAudienceKey($raw);
        if ($canonical !== null) {
            $request->merge(['audience' => $canonical]);
        }
    }
}
