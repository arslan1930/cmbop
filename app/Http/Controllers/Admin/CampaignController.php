<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AudienceCampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailNotificationPreference;
use App\Services\AudienceInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    public function index(AudienceInventoryService $inventory)
    {
        $stats = $inventory->stats();
        $campaigns = EmailCampaign::query()
            ->with('creator')
            ->latest('id')
            ->paginate(15);

        $advertisers = $inventory->queryForRole('advertiser')->get(['id', 'name', 'email']);
        $publishers = $inventory->queryForRole('publisher')->get(['id', 'name', 'email']);

        return view('admin.campaigns.index', compact('stats', 'campaigns', 'advertisers', 'publishers'));
    }

    public function preview(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'body_html' => ['required', 'string', 'max:20000'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'url', 'max:500'],
        ]);

        $campaign = new EmailCampaign([
            'subject' => $data['subject'],
            'body_html' => $this->sanitizeBody($data['body_html']),
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $data['cta_url'] ?? null,
            'audience' => 'selected',
        ]);

        $mailable = new AudienceCampaignMail($campaign, auth()->user());
        $mailable->skipUserPreference = true;

        return response($mailable->render());
    }

    public function send(Request $request, AudienceInventoryService $inventory)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:180'],
            'body_html' => ['required', 'string', 'max:20000'],
            'audience' => ['required', Rule::in(['advertisers', 'publishers', 'both', 'selected'])],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'url', 'max:500'],
            'respect_preferences' => ['sometimes', 'boolean'],
        ]);

        if ($data['audience'] === 'selected' && empty($data['user_ids'])) {
            return back()->withInput()->with('error', 'Select at least one user for a custom audience.');
        }

        $recipients = $inventory->collect($data['audience'], $data['user_ids'] ?? []);
        if ($recipients->isEmpty()) {
            return back()->withInput()->with('error', 'No recipients found for that audience.');
        }

        $respectPrefs = $request->boolean('respect_preferences', true);

        $campaign = EmailCampaign::create([
            'name' => ($data['name'] ?? null) ?: $data['subject'],
            'subject' => $data['subject'],
            'body_html' => $this->sanitizeBody($data['body_html']),
            'audience' => $data['audience'],
            'selected_user_ids' => $data['audience'] === 'selected' ? array_values($data['user_ids'] ?? []) : null,
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $data['cta_url'] ?? null,
            'recipients_count' => $recipients->count(),
            'status' => EmailCampaign::STATUS_SENDING,
            'respect_preferences' => $respectPrefs,
            'created_by' => auth()->id(),
        ]);

        $sent = 0;
        $skipped = 0;

        foreach ($recipients as $user) {
            if ($respectPrefs && !EmailNotificationPreference::allows($user, 'marketing_emails')) {
                $skipped++;
                continue;
            }

            try {
                $mailable = new AudienceCampaignMail($campaign, $user);
                $mailable->notificationType = 'audience_campaign';
                $mailable->dedupeKey = 'audience_campaign:' . $campaign->id . ':user:' . $user->id;
                $mailable->skipUserPreference = true; // already checked above

                Mail::to($user->email)->send($mailable);
                $sent++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('Campaign send failed', [
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $campaign->update([
            'sent_count' => $sent,
            'skipped_count' => $skipped,
            'status' => $sent > 0 ? EmailCampaign::STATUS_SENT : EmailCampaign::STATUS_FAILED,
            'sent_at' => now(),
        ]);

        $msg = "Campaign sent to {$sent} recipient(s).";
        if ($skipped > 0) {
            $msg .= " Skipped {$skipped} (preferences or errors).";
        }

        return redirect()
            ->route('admin.campaigns.index')
            ->with($sent > 0 ? 'success' : 'error', $msg);
    }

    protected function sanitizeBody(string $html): string
    {
        // Allow basic formatting tags only
        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><blockquote>';
        $clean = strip_tags($html, $allowed);

        // Ensure paragraphs if plain text
        if (!str_contains($clean, '<')) {
            $clean = '<p>' . nl2br(e($html)) . '</p>';
        }

        return $clean;
    }
}
