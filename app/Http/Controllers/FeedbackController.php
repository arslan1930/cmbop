<?php

namespace App\Http\Controllers;

use App\Models\ProblemReport;
use App\Models\Suggestion;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function storeProblem(Request $request)
    {
        $user = $request->user();
        $rules = [
            'subject' => 'required|string|max:160',
            'message' => 'required|string|min:10|max:3000',
            'page_url' => 'nullable|string|max:500',
        ];

        if (! $user) {
            $rules['name'] = 'required|string|max:120';
            $rules['email'] = 'required|email|max:190';
        } else {
            $rules['name'] = 'nullable|string|max:120';
            $rules['email'] = 'nullable|email|max:190';
        }

        $data = $request->validate($rules);

        $report = ProblemReport::create([
            'user_id' => $user?->id,
            'name' => $data['name'] ?? $user?->name,
            'email' => $data['email'] ?? $user?->email,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'page_url' => $data['page_url'] ?? $request->headers->get('referer'),
            'role_context' => $user?->activeRole(),
            'status' => 'pending',
        ]);

        if ($user) {
            ActivityLogger::log(
                'feedback.problem',
                $user->name.' reported a problem: '.$report->subject,
                $report,
                ['report_id' => $report->id],
                $report->subject
            );
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
            'page_url' => 'nullable|string|max:500',
        ];

        if (! $user) {
            $rules['name'] = 'required|string|max:120';
            $rules['email'] = 'required|email|max:190';
        } else {
            $rules['name'] = 'nullable|string|max:120';
            $rules['email'] = 'nullable|email|max:190';
        }

        $data = $request->validate($rules);

        $suggestion = Suggestion::create([
            'user_id' => $user?->id,
            'name' => $data['name'] ?? $user?->name,
            'email' => $data['email'] ?? $user?->email,
            'category' => $data['category'] ?? 'general',
            'message' => $data['message'],
            'page_url' => $data['page_url'] ?? $request->headers->get('referer'),
            'status' => 'pending',
        ]);

        if ($user) {
            ActivityLogger::log(
                'feedback.suggestion',
                $user->name.' sent a suggestion',
                $suggestion,
                ['suggestion_id' => $suggestion->id],
                'Suggestion'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Thanks for the suggestion — we read every one.',
        ]);
    }
}
