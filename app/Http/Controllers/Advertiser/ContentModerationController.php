<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\ContentModeration\ContentModerationService;
use Illuminate\Http\Request;

class ContentModerationController extends Controller
{
    public function scan(Request $request, ContentModerationService $moderation)
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:1000'],
        ]);

        $result = $moderation->scan($data['url'], $request->user());

        return response()->json([
            'success' => true,
            'passed' => $result['passed'],
            'status' => $result['status'],
            'title' => $result['user_title'],
            'message' => $result['user_message'],
            'report' => $result['report'],
            'scan_token' => $result['scan_token'],
            'log_id' => $result['log']?->id,
        ]);
    }
}
