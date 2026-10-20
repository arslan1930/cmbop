<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\AdvertiserApiToken;
use App\Models\AdvertiserWebhookEndpoint;
use App\Services\Advertiser\AdvertiserApiKeyService;
use App\Services\Advertiser\AdvertiserWebhookService;
use App\Services\Mcp\McpEndpoints;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiSettingsController extends Controller
{
    public function index(Request $request, McpEndpoints $endpoints, AdvertiserWebhookService $webhooks): View
    {
        $user = $request->user();
        $mcpUrl = $endpoints->customerUrl();
        $sseUrl = $endpoints->sseUrl();

        return view('advertiser.api.index', [
            'tokens' => AdvertiserApiToken::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->get(),
            'webhooks' => AdvertiserWebhookEndpoint::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (AdvertiserWebhookEndpoint $row) => $webhooks->serializeEndpoint($row)),
            'plainToken' => $request->session()->pull('advertiser_api_plain_token'),
            'webhookSecret' => $request->session()->pull('advertiser_webhook_secret'),
            'scopes' => AdvertiserApiKeyService::SCOPES,
            'webhookEvents' => config('advertiser_api.events', []),
            'catalogUrl' => url('/api/v1/catalog'),
            'docsUrl' => url('/api/docs/advertiser'),
            'mcpUrl' => $mcpUrl,
            'sseUrl' => $sseUrl,
            'claudeHttpSnippet' => 'claude mcp add --transport http --scope user seolinkbuildings '.$mcpUrl,
            'claudeSseSnippet' => 'claude mcp add --transport sse --scope user seolinkbuildings '.$sseUrl,
            'cursorSnippet' => 'cursor mcp add seolinkbuildings --transport http '.$mcpUrl,
            'cursorJson' => json_encode([
                'mcpServers' => [
                    'seolinkbuildings' => [
                        'type' => 'http',
                        'url' => $mcpUrl,
                        'headers' => [
                            'Authorization' => 'Bearer YOUR_KEY',
                        ],
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function store(Request $request, AdvertiserApiKeyService $keys): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'mode' => ['nullable', 'in:live,test'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'in:'.implode(',', AdvertiserApiKeyService::SCOPES)],
        ]);

        $issued = $keys->issue(
            $request->user(),
            $data['name'],
            $data['scopes'] ?? AdvertiserApiKeyService::SCOPES,
            $data['mode'] ?? 'live'
        );
        $request->session()->flash('advertiser_api_plain_token', $issued['plain']);

        return redirect()
            ->route('advertiser.api.index')
            ->with('success', 'Copy this key now. It will not be shown again.');
    }

    public function destroy(Request $request, AdvertiserApiToken $token, AdvertiserApiKeyService $keys): RedirectResponse
    {
        if ((int) $token->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $keys->revoke($token);

        return redirect()
            ->route('advertiser.api.index')
            ->with('success', 'API key revoked.');
    }

    public function storeWebhook(Request $request, AdvertiserWebhookService $webhooks): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2000'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $created = $webhooks->createEndpoint(
                $request->user(),
                (string) $data['url'],
                $data['events'] ?? ['*'],
                $data['description'] ?? null
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $request->session()->flash('advertiser_webhook_secret', $created['secret']);

        return redirect()
            ->route('advertiser.api.index')
            ->with('success', 'Copy this webhook secret now. It will not be shown again.');
    }

    public function destroyWebhook(Request $request, AdvertiserWebhookEndpoint $webhook, AdvertiserWebhookService $webhooks): RedirectResponse
    {
        if ((int) $webhook->user_id !== (int) $request->user()->id) {
            abort(404);
        }
        $webhooks->deleteEndpoint($webhook);

        return redirect()
            ->route('advertiser.api.index')
            ->with('success', 'Webhook endpoint removed.');
    }

    public function testWebhook(Request $request, AdvertiserWebhookEndpoint $webhook, AdvertiserWebhookService $webhooks): RedirectResponse
    {
        if ((int) $webhook->user_id !== (int) $request->user()->id) {
            abort(404);
        }
        if (! $webhook->isEnabled()) {
            return back()->with('error', 'Enable the webhook before sending a test event.');
        }
        $event = $webhooks->sendTest($webhook);

        return back()->with('success', 'Sent a signed webhook.test event ('.($event?->public_id ?? 'queued').').');
    }
}
