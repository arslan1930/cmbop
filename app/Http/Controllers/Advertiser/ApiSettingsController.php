<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\AdvertiserApiToken;
use App\Services\Mcp\McpEndpoints;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiSettingsController extends Controller
{
    public function index(Request $request, McpEndpoints $endpoints): View
    {
        $user = $request->user();
        $mcpUrl = $endpoints->customerUrl();
        $sseUrl = $endpoints->sseUrl();

        return view('advertiser.api.index', [
            'tokens' => AdvertiserApiToken::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->get(),
            'plainToken' => $request->session()->pull('advertiser_api_plain_token'),
            'catalogUrl' => url('/api/v1/catalog'),
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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $issued = AdvertiserApiToken::issue($request->user(), $data['name']);
        $request->session()->flash('advertiser_api_plain_token', $issued['plain']);

        return redirect()
            ->route('advertiser.api.index')
            ->with('success', 'Copy this key now. It will not be shown again.');
    }

    public function destroy(Request $request, AdvertiserApiToken $token): RedirectResponse
    {
        if ((int) $token->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $token->delete();

        return redirect()
            ->route('advertiser.api.index')
            ->with('success', 'API key revoked.');
    }
}
