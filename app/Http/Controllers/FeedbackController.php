<?php

namespace App\Http\Controllers;

use App\Models\ProblemReport;
use App\Models\Suggestion;
use App\Services\ActivityLogger;
use App\Services\CommunityInboxNotifier;
use App\Support\CommunityInbox;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FeedbackController extends Controller
{
    /**
     * GET /feedback/* is listed in page source. Crawlers must get a 200
     * with noindex — not a 4xx and not a homepage redirect (soft-404).
     */
    public function showEndpoint()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Feedback</title>
</head>
<body>
    <p>This form is submitted from SEOLinkBuildings pages. There is nothing to index here.</p>
</body>
</html>
HTML;

        return response($html, 200)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function storeProblem(Request $request)
    {
        $user = $request->user();
        $rules = [
            'subject' => 'required|string|max:160',
            'message' => 'required|string|min:10|max:3000',
            'page_url' => 'nullable|string|max:'.CommunityInbox::PAGE_URL_MAX,
        ];

        if (! $user) {
            $rules['name'] = 'required|string|max:120';
            $rules['email'] = 'required|email|max:190';
        } else {
            $rules['name'] = 'nullable|string|max:120';
            $rules['email'] = 'nullable|email|max:190';
        }

        $data = $request->validate($rules);

        try {
            $report = ProblemReport::create([
                'user_id' => $user?->id,
                'name' => $data['name'] ?? $user?->name,
                'email' => $data['email'] ?? $user?->email,
                'subject' => $data['subject'],
                'message' => $data['message'],
                'page_url' => CommunityInbox::storedPageUrl($data['page_url'] ?? $request->headers->get('referer')),
                'role_context' => $user?->activeRole(),
                'status' => 'pending',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not submit that report. Please try again.'),
            ], 500);
        }

        try {
            ActivityLogger::log(
                'feedback.problem',
                ($user?->name ?: ($report->name ?: 'Guest')).' reported a problem: '.$report->subject,
                $report,
                ['report_id' => $report->id, 'email' => $report->email],
                $report->subject
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to log problem report: '.$e->getMessage(), [
                'report_id' => $report->id,
            ]);
        }

        try {
            app(CommunityInboxNotifier::class)->notifyAdminsNewProblem($report);
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins about problem report: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Thanks — your report was submitted. Our team will review it shortly.',
        ]);
    }

    public function storeSuggestion(Request $request)
    {
        $user = $request->user();
        $rules = [
            'category' => 'nullable|string|in:general,feature,ux,pricing,other',
            'message' => 'required|string|min:10|max:3000',
            'page_url' => 'nullable|string|max:'.CommunityInbox::PAGE_URL_MAX,
        ];

        if (! $user) {
            $rules['name'] = 'required|string|max:120';
            $rules['email'] = 'required|email|max:190';
        } else {
            $rules['name'] = 'nullable|string|max:120';
            $rules['email'] = 'nullable|email|max:190';
        }

        $data = $request->validate($rules);

        try {
            $suggestion = Suggestion::create([
                'user_id' => $user?->id,
                'name' => $data['name'] ?? $user?->name,
                'email' => $data['email'] ?? $user?->email,
                'category' => $data['category'] ?? 'general',
                'message' => $data['message'],
                'page_url' => CommunityInbox::storedPageUrl($data['page_url'] ?? $request->headers->get('referer')),
                'status' => 'pending',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not submit that suggestion. Please try again.'),
            ], 500);
        }

        try {
            ActivityLogger::log(
                'feedback.suggestion',
                ($user?->name ?: ($suggestion->name ?: 'Guest')).' sent a suggestion',
                $suggestion,
                ['suggestion_id' => $suggestion->id, 'email' => $suggestion->email],
                'Suggestion'
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to log suggestion: '.$e->getMessage(), [
                'suggestion_id' => $suggestion->id,
            ]);
        }

        try {
            app(CommunityInboxNotifier::class)->notifyAdminsNewSuggestion($suggestion);
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins about suggestion: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Thanks for the suggestion — we read every one.',
        ]);
    }
}
