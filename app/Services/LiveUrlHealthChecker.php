<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LiveUrlHealthChecker
{
    /**
     * Check that a live URL is publicly reachable.
     *
     * @return array{ok: bool, status: ?int, checked_at: Carbon, message: string}
     */
    public function check(string $url): array
    {
        $checkedAt = now();
        $url = trim($url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'ok' => false,
                'status' => null,
                'checked_at' => $checkedAt,
                'message' => 'Invalid URL',
            ];
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'User-Agent' => 'SEOLinkBuildings-LiveUrlCheck/1.0',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->withOptions(['allow_redirects' => true])
                ->get($url);

            $status = $response->status();
            $ok = $status >= 200 && $status < 400;

            return [
                'ok' => $ok,
                'status' => $status,
                'checked_at' => $checkedAt,
                'message' => $ok
                    ? 'Link looks publicly reachable.'
                    : 'Link returned HTTP '.$status.'. It may still work in a browser.',
            ];
        } catch (\Throwable $e) {
            Log::info('Live URL health check failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => null,
                'checked_at' => $checkedAt,
                'message' => 'Couldn’t verify the URL is reachable right now.',
            ];
        }
    }
}
