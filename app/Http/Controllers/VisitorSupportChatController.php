<?php

namespace App\Http\Controllers;

use App\Services\VisitorSupportChatService;
use App\Support\VisitorSupportChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitorSupportChatController extends Controller
{
    public function store(Request $request, VisitorSupportChatService $chat): JsonResponse
    {
        if (! class_exists(VisitorSupportChat::class)
            || ! method_exists(VisitorSupportChat::class, 'enabled')
            || ! VisitorSupportChat::enabled()) {
            return response()->json([
                'ok' => false,
                'message' => 'Support chat is not available right now.',
            ], 404);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'session_id' => ['nullable', 'string', 'max:64'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:2000'],
        ]);

        $message = trim((string) $data['message']);
        if ($message === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Write a message before sending.',
            ], 422);
        }

        $history = [];
        foreach ($data['history'] ?? [] as $item) {
            $history[] = [
                'role' => (string) $item['role'],
                'content' => trim((string) $item['content']),
            ];
        }

        try {
            $result = $chat->reply($message, $history);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'Support chat is not available right now.',
            ], 503);
        }
        $status = ($result['ok'] ?? false) ? 200 : 502;

        return response()->json($result, $status);
    }
}
