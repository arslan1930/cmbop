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
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    public function index(Request $request, AudienceInventoryService $inventory)
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

        $campaignStatus = search_text($request->query('status'));
        $attentionStatuses = [
            EmailCampaign::STATUS_QUEUED,
            EmailCampaign::STATUS_SENDING,
            EmailCampaign::STATUS_FAILED,
        ];
        if ($campaignStatus !== 'attention' && ! in_array($campaignStatus, $attentionStatuses, true)) {
            $campaignStatus = '';
        }

        try {
            if (! EmailCampaign::tableAvailable()) {
                $campaigns = new LengthAwarePaginator([], 0, 15);
            } else {
                $campaignQuery = EmailCampaign::query()->with('creator');
                if ($campaignStatus === 'attention') {
                    $campaignQuery->whereIn('status', $attentionStatuses)
                        ->orderBy('created_at')
                        ->orderBy('id');
                } elseif ($campaignStatus !== '') {
                    $campaignQuery->where('status', $campaignStatus)
                        ->orderBy('created_at')
                        ->orderBy('id');
                } else {
                    $campaignQuery->latest('id');
                }
                $campaigns = $campaignQuery->paginate(15)->withQueryString();
            }
        } catch (\Throwable $e) {
            Log::warning('Campaign list failed', ['error' => $e->getMessage()]);
            $campaigns = new LengthAwarePaginator([], 0, 15);
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

        $emailTemplates = collect(EmailCatalog::templates())
            ->reject(fn (array $tpl) => ($tpl['key'] ?? '') === 'audience_campaign')
            ->groupBy(fn (array $tpl) => $tpl['category'] ?: 'Other');

        return view('admin.campaigns.index', compact(
            'stats',
            'campaigns',
            'campaignStatus',
            'advertisers',
            'publishers',
            'pickerCapped',
            'emailTemplates'
        ));
    }

    public function show(Request $request, EmailCampaign $campaign)
    {
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
        if ($request->filled('template')) {
            $data = $request->validate([
                'template' => ['required', 'string', Rule::in($this->catalogTemplateKeys())],
                'audience' => ['nullable', 'string'],
            ]);

            $html = EmailCatalog::previewHtml(
                $data['template'],
                $this->emailCenterAudience($data['audience'] ?? null)
            );
            abort_unless($html, 404);

            return response($html);
        }

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

    public function fromTemplate(Request $request)
    {
        $data = $request->validate([
            'template' => ['required', 'string', Rule::in($this->catalogTemplateKeys())],
            'audience' => ['nullable', 'string'],
        ]);

        $key = $data['template'];
        $meta = EmailCatalog::get($key);
        abort_unless($meta, 404);

        $previewAudience = $this->emailCenterAudience($data['audience'] ?? null);
        $mailable = EmailCatalog::makeMailable($key, array_filter([
            'audience' => $previewAudience,
        ]));

        $html = $mailable?->render() ?? EmailCatalog::previewHtml($key, $previewAudience);
        if (! is_string($html) || trim($html) === '') {
            return response()->json([
                'message' => 'That template could not be rendered.',
            ], 422);
        }

        $fields = $mailable
            ? $this->composeFieldsFromMailable($mailable, $meta['name'] ?? $key, $html)
            : $this->frameworkComposeFields($key, $meta['name'] ?? $key);

        if (CampaignHtml::isBlank($fields['body_html'])) {
            $fields['body_html'] = $this->reusableTemplateBody($html, $meta['name'] ?? $key);
        }

        return response()->json([
            'subject' => Str::limit($fields['subject'], 180, ''),
            'body_html' => Str::limit($fields['body_html'], 20000, ''),
            'html' => $html,
            'cta_label' => Str::limit($fields['cta_label'], 80, ''),
            'cta_url' => $fields['cta_url'],
        ]);
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
        try {
            $recipients = $inventory->collectRecipientRows($data['audience'], $data['user_ids'] ?? [], $includeUnverified)
                ->unique('id')
                ->values();
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with(
                'error',
                UserFacingError::message($e, 'We could not load campaign recipients. Please try again.')
            );
        }
        if ($recipients->isEmpty()) {
            return back()->withInput()->with('error', 'No recipients found for that audience.');
        }

        $respectPrefs = $request->boolean('respect_preferences');
        $count = $recipients->count();

        try {
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
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with(
                'error',
                UserFacingError::message($e, 'We could not queue this campaign. Please try again.')
            );
        }

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

    /**
     * @return list<string>
     */
    protected function catalogTemplateKeys(): array
    {
        return array_values(array_filter(
            array_keys(EmailCatalog::templates()),
            fn (string $key) => $key !== 'audience_campaign'
        ));
    }

    protected function emailCenterAudience(?string $audience): ?string
    {
        if (in_array($audience, ['advertiser', 'publisher', 'admin'], true)) {
            return $audience;
        }

        $canonical = is_string($audience)
            ? (AudienceInventoryService::canonicalAudienceKey($audience) ?? $audience)
            : null;

        return match ($canonical) {
            AudienceInventoryService::AUDIENCE_PUBLISHERS,
            AudienceInventoryService::AUDIENCE_PUBLISHERS_NO_SITES,
            AudienceInventoryService::AUDIENCE_PUBLISHERS_NO_ACTIVE_SITES => 'publisher',
            default => 'advertiser',
        };
    }

    /**
     * @return array{subject: string, body_html: string, cta_label: string, cta_url: string}
     */
    protected function composeFieldsFromMailable(Mailable $mailable, string $fallbackName, string $renderedHtml = ''): array
    {
        $subject = trim((string) $mailable->subject);
        if ($subject === '' && method_exists($mailable, 'envelope')) {
            try {
                $subject = trim((string) ($mailable->envelope()->subject ?? ''));
            } catch (\Throwable) {
                $subject = '';
            }
        }

        $data = is_array($mailable->viewData) ? $mailable->viewData : [];
        $ctaLabel = trim((string) ($data['ctaLabel'] ?? $data['cta_label'] ?? ''));
        $explicitUrl = $this->safeComposeCtaUrl($data['ctaUrl'] ?? $data['cta_url'] ?? null);
        $button = $explicitUrl !== ''
            ? $this->mailButtonMatchingUrl($renderedHtml, $explicitUrl)
            : $this->firstMailButton($renderedHtml);
        $ctaUrl = $explicitUrl !== '' ? $explicitUrl : $this->safeComposeCtaUrl($button['url']);
        if ($ctaLabel === '' && $button['label'] !== '') {
            $ctaLabel = $button['label'];
        }

        return [
            'subject' => $subject !== '' ? $subject : $fallbackName,
            'body_html' => $this->innerMailableBody($mailable),
            'cta_label' => $ctaLabel,
            'cta_url' => $ctaUrl,
        ];
    }

    /**
     * Every mail button, in document order. URL and label stay paired.
     *
     * @return list<array{url: string, label: string}>
     */
    private function mailButtons(string $html): array
    {
        if ($html === '' || preg_match_all('/<a\b[^>]*\bclass="[^"]*\bbutton\b[^"]*"[^>]*>.*?<\/a>/is', $html, $tags) < 1) {
            return [];
        }

        $buttons = [];
        foreach ($tags[0] as $tag) {
            if (! preg_match('/\bhref="([^"]+)"/i', $tag, $href) || ! preg_match('/>(.*)<\/a>/is', $tag, $text)) {
                continue;
            }
            $label = trim(html_entity_decode(strip_tags($text[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
            $buttons[] = [
                'url' => html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'label' => $label,
            ];
        }

        return $buttons;
    }

    /**
     * @return array{url: string, label: string}
     */
    private function firstMailButton(string $html): array
    {
        return $this->mailButtons($html)[0] ?? ['url' => '', 'label' => ''];
    }

    /**
     * @return array{url: string, label: string}
     */
    private function mailButtonMatchingUrl(string $html, string $url): array
    {
        foreach ($this->mailButtons($html) as $button) {
            if ($this->safeComposeCtaUrl($button['url']) === $url) {
                return $button;
            }
        }

        return ['url' => '', 'label' => ''];
    }

    /**
     * @return array{subject: string, body_html: string, cta_label: string, cta_url: string}
     */
    protected function frameworkComposeFields(string $key, string $fallbackName): array
    {
        return match ($key) {
            'password_reset' => [
                'subject' => $fallbackName,
                'body_html' => '<p>You are receiving this email because we received a password reset request for your account.</p><p>This password reset link will expire in 60 minutes.</p><p>If you did not request a password reset, no further action is required.</p>',
                'cta_label' => 'Reset Password',
                'cta_url' => rtrim(app_public_url(), '/').'/password/reset/preview-token',
            ],
            'email_verification' => [
                'subject' => $fallbackName,
                'body_html' => '<p>Thanks for creating your account. Please verify your email address to activate login and start using the marketplace.</p><p>This verification link expires in 60 minutes.</p><p>If you did not create an account, no further action is required.</p>',
                'cta_label' => 'Click to verify',
                'cta_url' => EmailCatalog::previewVerificationUrl(),
            ],
            default => [
                'subject' => $fallbackName,
                'body_html' => '',
                'cta_label' => '',
                'cta_url' => '',
            ],
        };
    }

    protected function innerMailableBody(Mailable $mailable): string
    {
        $name = is_string($mailable->markdown) && $mailable->markdown !== ''
            ? $mailable->markdown
            : (is_string($mailable->view) ? $mailable->view : '');

        if ($name === '') {
            return '';
        }

        try {
            $raw = view($name, $mailable->viewData)->render();
        } catch (\Throwable) {
            return '';
        }

        return $this->reusableTemplateBody($raw, '');
    }

    protected function safeComposeCtaUrl(mixed $url): string
    {
        if (! is_string($url)) {
            return '';
        }

        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $url = rtrim(app_public_url(), '/').$url;
        }

        return CampaignHtml::isSafeHttpUrl($url) ? $url : '';
    }

    protected function reusableTemplateBody(string $html, string $fallbackName): string
    {
        $bodyHtml = CampaignHtml::sanitize($html);
        if (! CampaignHtml::isBlank($bodyHtml)) {
            return $bodyHtml;
        }

        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        if ($text !== '') {
            $fromText = CampaignHtml::sanitize($text);
            if (! CampaignHtml::isBlank($fromText)) {
                return $fromText;
            }
        }

        return '<p>'.e($fallbackName).'</p>';
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
