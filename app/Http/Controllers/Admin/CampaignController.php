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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    public function index(AudienceInventoryService $inventory)
    {
        return $this->composeView($inventory);
    }

    public function drafts()
    {
        try {
            $drafts = EmailCampaign::tableAvailable()
                ? EmailCampaign::query()
                    ->with('creator')
                    ->where('status', EmailCampaign::STATUS_DRAFT)
                    ->latest('updated_at')
                    ->paginate(20)
                : new LengthAwarePaginator([], 0, 20);
        } catch (\Throwable $e) {
            Log::warning('Campaign draft list failed', ['error' => $e->getMessage()]);
            $drafts = new LengthAwarePaginator([], 0, 20);
        }

        $draftCount = $drafts->total();
        $campaignTab = 'drafts';

        return view('admin.campaigns.drafts', compact('drafts', 'draftCount', 'campaignTab'));
    }

    public function store(Request $request, AudienceInventoryService $inventory)
    {
        return $this->persistDraft($request, $inventory);
    }

    public function edit(AudienceInventoryService $inventory, EmailCampaign $campaign)
    {
        return $this->composeView($inventory, $this->editableDraftOrAbort($campaign));
    }

    public function update(Request $request, AudienceInventoryService $inventory, EmailCampaign $campaign)
    {
        return $this->persistDraft($request, $inventory, $this->editableDraftOrAbort($campaign));
    }

    public function destroy(EmailCampaign $campaign)
    {
        $draft = $this->editableDraftOrAbort($campaign);
        $name = $draft->name ?: $draft->subject;
        $draft->delete();

        ActivityLogger::tryLog(
            'campaign.draft_deleted',
            "Deleted draft \"{$name}\".",
            null,
            ['campaign_id' => $draft->id]
        );

        return redirect()
            ->route('admin.campaigns.drafts')
            ->with('success', 'Draft deleted.');
    }

    public function show(Request $request, EmailCampaign $campaign)
    {
        if ($campaign->isDraft()) {
            return redirect()->route('admin.campaigns.drafts.edit', $campaign);
        }

        try {
            EmailCampaign::recoverStalled();
            $campaign->refresh();
        } catch (\Throwable $e) {
            Log::warning('Campaign stall recovery failed', ['error' => $e->getMessage()]);
        }

        $status = search_text($request->get('status'));
        $allowed = [
            EmailCampaignRecipient::STATUS_PENDING,
            EmailCampaignRecipient::STATUS_QUEUED,
            EmailCampaignRecipient::STATUS_DELIVERED,
            EmailCampaignRecipient::STATUS_FAILED,
            EmailCampaignRecipient::STATUS_SKIPPED,
        ];
        if (! in_array($status, $allowed, true)) {
            $status = '';
        }

        try {
            $recipients = $campaign->recipients()
                ->with(['user', 'emailLog'])
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->orderBy('id')
                ->paginate(25)
                ->withQueryString();
            $counts = $campaign->recipientStatusCounts();
        } catch (\Throwable $e) {
            Log::warning('Campaign recipient list failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            $recipients = new LengthAwarePaginator([], 0, 25);
            $counts = [
                'pending' => 0,
                'queued' => 0,
                'delivered' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];
        }

        return view('admin.campaigns.show', compact(
            'campaign',
            'recipients',
            'counts',
            'status'
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

        $data = $request->validate($this->campaignContentRules());

        if (! EmailCampaign::tableAvailable()
            || ! $this->schemaTableAvailable((new EmailCampaignRecipient)->getTable())) {
            return back()->withInput()->with('error', 'Campaigns are unavailable on this database.');
        }

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

        $campaign = DB::transaction(function () use ($data, $recipients, $count, $respectPrefs, $includeUnverified, $bodyHtml) {
            $selectedIds = $data['audience'] === 'selected'
                ? $recipients->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                : null;

            $campaign = EmailCampaign::create(EmailCampaign::attributesThatExist(array_merge(
                $this->hydrateCampaignAttributes($data, $bodyHtml, $selectedIds, $respectPrefs, $includeUnverified),
                [
                    'recipients_count' => $count,
                    'sent_count' => 0,
                    'skipped_count' => 0,
                    'status' => EmailCampaign::STATUS_QUEUED,
                    'created_by' => auth()->id(),
                ]
            )));

            $now = now();
            foreach ($recipients->chunk(200) as $chunk) {
                EmailCampaignRecipient::query()->insert($chunk->map(fn ($user) => [
                    'email_campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'email' => trim((string) $user->email),
                    'status' => EmailCampaignRecipient::STATUS_PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            return $campaign;
        });

        try {
            SendEmailCampaignJob::dispatch($campaign->id);
        } catch (\Throwable $e) {
            Log::error('Campaign job dispatch failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            $campaign->refresh()->recountRecipientTotals();
            if ($campaign->status === EmailCampaign::STATUS_SENT) {
                return redirect()
                    ->route('admin.campaigns.index')
                    ->with('success', 'This campaign was already sent. Recipients were not queued again.');
            }

            EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaign->id)
                ->where('status', EmailCampaignRecipient::STATUS_PENDING)
                ->update([
                    'status' => EmailCampaignRecipient::STATUS_FAILED,
                    'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
                ]);
            // Terminal first so recount can still promote FAILED → SENT
            // when some recipients already delivered.
            $campaign->update([
                'status' => EmailCampaign::STATUS_FAILED,
                'sent_at' => now(),
            ]);
            $campaign->refresh()->recountRecipientTotals();

            return back()->withInput()->with('error', 'Campaign was saved but could not be queued. Try again.');
        }

        ActivityLogger::tryLog(
            'campaign.queued',
            "Queued campaign \"{$campaign->name}\" for {$count} recipient(s).",
            $campaign,
            [
                'audience' => $campaign->audience,
                'recipients' => $count,
            ]
        );

        return redirect()
            ->route('admin.campaigns.index')
            ->with('success', "Campaign queued for {$count} recipient(s).");
    }

    /**
     * Shared compose payload for send and (later) save-draft.
     *
     * @return array<string, list<mixed>>
     */
    protected function campaignContentRules(bool $requireName = false): array
    {
        return array_merge($this->audienceInputRules(), [
            'name' => [$requireName ? 'required' : 'nullable', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:180'],
            'body_html' => ['required', 'string', 'max:20000'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => $this->ctaUrlRules(),
            'respect_preferences' => ['boolean'],
        ]);
    }

    /**
     * @param  list<int>|null  $selectedUserIds
     * @return array<string, mixed>
     */
    protected function hydrateCampaignAttributes(
        array $data,
        string $bodyHtml,
        ?array $selectedUserIds,
        bool $respectPreferences,
        bool $includeUnverified,
    ): array {
        return [
            'name' => filled($data['name'] ?? null) ? $data['name'] : $data['subject'],
            'subject' => $data['subject'],
            'body_html' => $bodyHtml,
            'audience' => $data['audience'],
            'selected_user_ids' => ($data['audience'] ?? null) === 'selected'
                ? array_values(array_map('intval', $selectedUserIds ?? []))
                : null,
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $this->safeCtaUrl($data['cta_url'] ?? null),
            'respect_preferences' => $respectPreferences,
            'include_unverified' => $includeUnverified,
        ];
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

    private function composeView(AudienceInventoryService $inventory, ?EmailCampaign $editingDraft = null)
    {
        try {
            EmailCampaign::recoverStalled();
        } catch (\Throwable $e) {
            Log::warning('Campaign stall recovery failed', ['error' => $e->getMessage()]);
        }

        try {
            $stats = $inventory->stats(includeUnverified: false);
        } catch (\Throwable $e) {
            Log::warning('Campaign audience stats failed', ['error' => $e->getMessage()]);
            $stats = $this->emptyCampaignStats();
        }

        $campaignTab = $editingDraft ? 'compose' : $this->campaignListTab();

        try {
            $campaigns = EmailCampaign::tableAvailable()
                ? EmailCampaign::query()
                    ->with('creator')
                    ->where('status', '!=', EmailCampaign::STATUS_DRAFT)
                    ->when($campaignTab === 'sending', function ($query) {
                        $query->whereIn('status', [
                            EmailCampaign::STATUS_QUEUED,
                            EmailCampaign::STATUS_SENDING,
                        ]);
                    })
                    ->when($campaignTab === 'sent', function ($query) {
                        $query->whereIn('status', [
                            EmailCampaign::STATUS_SENT,
                            EmailCampaign::STATUS_FAILED,
                        ]);
                    })
                    ->latest('id')
                    ->paginate(15)
                    ->withQueryString()
                : new LengthAwarePaginator([], 0, 15);
            $draftCount = EmailCampaign::tableAvailable()
                ? EmailCampaign::query()->where('status', EmailCampaign::STATUS_DRAFT)->count()
                : 0;
        } catch (\Throwable $e) {
            Log::warning('Campaign list failed', ['error' => $e->getMessage()]);
            $campaigns = new LengthAwarePaginator([], 0, 15);
            $draftCount = 0;
        }

        try {
            $advertisers = $inventory->pickerUsers('advertiser');
            $publishers = $inventory->pickerUsers('publisher');
            $pickerCapped = $inventory->pickerIsCapped('advertiser')
                || $inventory->pickerIsCapped('publisher');
        } catch (\Throwable $e) {
            Log::warning('Campaign audience picker failed', ['error' => $e->getMessage()]);
            $advertisers = collect();
            $publishers = collect();
            $pickerCapped = false;
        }

        return view('admin.campaigns.index', compact(
            'stats',
            'campaigns',
            'advertisers',
            'publishers',
            'pickerCapped',
            'editingDraft',
            'draftCount',
            'campaignTab'
        ));
    }

    private function campaignListTab(): string
    {
        $tab = search_text(request('tab'));

        return in_array($tab, ['sending', 'sent'], true) ? $tab : 'compose';
    }

    private function persistDraft(Request $request, AudienceInventoryService $inventory, ?EmailCampaign $existing = null)
    {
        $this->canonicalizeAudienceInput($request);

        $data = $request->validate($this->campaignContentRules());

        if (! EmailCampaign::tableAvailable()) {
            return back()->withInput()->with('error', 'Campaigns are unavailable on this database.');
        }

        $bodyHtml = CampaignHtml::sanitize($data['body_html']);
        if (CampaignHtml::isBlank($data['body_html'])) {
            return back()->withInput()->withErrors([
                'body_html' => 'Write a message before saving this draft.',
            ]);
        }

        $selectedIds = ($data['audience'] ?? null) === 'selected'
            ? $inventory->collectRecipientRows('selected', $data['user_ids'] ?? [], true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all()
            : null;

        $attrs = EmailCampaign::attributesThatExist(array_merge(
            $this->hydrateCampaignAttributes(
                $data,
                $bodyHtml,
                $selectedIds,
                $request->boolean('respect_preferences'),
                $request->boolean('include_unverified')
            ),
            [
                'status' => EmailCampaign::STATUS_DRAFT,
                'recipients_count' => 0,
                'sent_count' => 0,
                'skipped_count' => 0,
            ]
        ));

        if ($existing) {
            $existing->update($attrs);
            $draft = $existing->fresh() ?? $existing;
        } else {
            $attrs['created_by'] = auth()->id();
            $draft = EmailCampaign::create($attrs);
        }

        ActivityLogger::tryLog(
            'campaign.draft_saved',
            'Saved draft "'.($draft->name ?: $draft->subject).'".',
            $draft,
            ['audience' => $draft->audience]
        );

        return redirect()
            ->route('admin.campaigns.drafts.edit', $draft)
            ->with('success', 'Draft saved.');
    }

    private function editableDraftOrAbort(EmailCampaign $campaign): EmailCampaign
    {
        if (! $campaign->isEditableDraft()) {
            abort(404);
        }

        return $campaign;
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

    /**
     * @return array<string, int>
     */
    private function emptyCampaignStats(): array
    {
        return [
            'advertisers' => 0,
            'publishers' => 0,
            'both_unique' => 0,
            'advertisers_no_orders' => 0,
            'advertisers_never_checked_out' => 0,
            'advertisers_no_paid_orders' => 0,
            'advertisers_paid_orders' => 0,
            'publishers_no_sites' => 0,
            'publishers_no_active_sites' => 0,
            'advertisers_never_deposited' => 0,
            'advertisers_deposited_no_orders' => 0,
        ];
    }

    private function schemaTableAvailable(string $table): bool
    {
        try {
            if (! Schema::hasTable($table)) {
                return false;
            }
            DB::table($table)->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
