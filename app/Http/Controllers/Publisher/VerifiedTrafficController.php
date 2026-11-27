<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteTrafficConnection;
use App\Services\VerifiedTraffic\GoogleTrafficClient;
use App\Services\VerifiedTraffic\GoogleTrafficException;
use App\Services\VerifiedTraffic\VerifiedTrafficService;
use App\Support\VerifiedTraffic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VerifiedTrafficController extends Controller
{
    public function redirect(Site $site, VerifiedTrafficService $service): RedirectResponse
    {
        $this->authorizeSite($site);
        $this->requireConnectable();

        $url = $service->googleRedirect($site, auth()->user());

        return redirect()->away($url);
    }

    public function callback(Request $request, VerifiedTrafficService $service): RedirectResponse
    {
        if ($error = $request->query('error')) {
            $denied = $error === 'access_denied';

            return redirect()
                ->route('publisher.websites')
                ->with('error', $denied
                    ? __('messages.verified_traffic_oauth_cancelled')
                    : __('messages.verified_traffic_oauth_failed'));
        }

        $this->requireConnectable();

        try {
            $decoded = $service->decodeOauthState($request->query('state'));
            if ($decoded['user_id'] !== (int) auth()->id()) {
                abort(403);
            }
            $site = Site::query()
                ->where('publisher_id', auth()->id())
                ->findOrFail($decoded['site_id']);
            $googleUser = app(GoogleTrafficClient::class)->userFromCallback();
            $service->startConnection($site, auth()->user(), $googleUser);
        } catch (GoogleTrafficException $e) {
            return redirect()
                ->route('publisher.websites')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('publisher.sites.google.properties', $site)
            ->with('success', __('messages.verified_traffic_oauth_ok'));
    }

    public function properties(Site $site): View|RedirectResponse
    {
        $this->authorizeSite($site);
        if (! VerifiedTraffic::schemaReady()) {
            return redirect()->route('publisher.websites')->with('error', __('messages.verified_traffic_unavailable'));
        }

        $connection = SiteTrafficConnection::query()->where('site_id', $site->id)->first();
        $ga4 = [];
        $gsc = [];
        $listError = null;
        $connected = $connection
            && $connection->status === VerifiedTraffic::STATUS_CONNECTED
            && $connection->hasProperty();
        $wantsPicker = ! $connected || request()->boolean('change');
        if ($connection && VerifiedTraffic::oauthConfigured() && $wantsPicker) {
            if ($this->demoFixturesEnabled()) {
                $listed = $this->demoProperties($site);
                $ga4 = $listed['ga4'];
                $gsc = $listed['gsc'];
            } else {
                try {
                    $listed = app(VerifiedTrafficService::class)->listMatchingProperties($site);
                    $ga4 = $listed['ga4'];
                    $gsc = $listed['gsc'];
                } catch (GoogleTrafficException $e) {
                    $listError = $e->getMessage();
                }
            }
        }

        return view('publisher.verified-traffic.properties', [
            'site' => $site,
            'connection' => $connection,
            'ga4' => $ga4,
            'gsc' => $gsc,
            'listError' => $listError,
            'oauthConfigured' => VerifiedTraffic::oauthConfigured(),
            'showPicker' => (bool) $wantsPicker,
        ]);
    }

    public function saveProperties(Request $request, Site $site, VerifiedTrafficService $service): RedirectResponse
    {
        $this->authorizeSite($site);
        $this->requireConnectable();

        $ga4 = $request->input('ga4_property_id');
        $gsc = $request->input('gsc_site_url');
        if ($ga4 === '') {
            $ga4 = null;
        }
        if ($gsc === '') {
            $gsc = null;
        }

        try {
            $service->saveProperties($site, is_string($ga4) ? $ga4 : null, is_string($gsc) ? $gsc : null);
        } catch (GoogleTrafficException $e) {
            return redirect()
                ->route('publisher.sites.google.properties', $site)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('publisher.sites.google.properties', $site)
            ->with('success', __('messages.verified_traffic_sync_queued'));
    }

    public function disconnect(Site $site, VerifiedTrafficService $service): RedirectResponse
    {
        $this->authorizeSite($site);
        if (! VerifiedTraffic::schemaReady()) {
            return redirect()->route('publisher.websites');
        }

        $service->disconnect($site);

        return redirect()
            ->route('publisher.websites')
            ->with('success', __('messages.verified_traffic_disconnected'));
    }

    private function authorizeSite(Site $site): void
    {
        abort_unless((int) $site->publisher_id === (int) auth()->id(), 403);
    }

    private function requireConnectable(): void
    {
        abort_unless(VerifiedTraffic::connectAvailable(), 404);
    }

    private function demoFixturesEnabled(): bool
    {
        return str_starts_with((string) config('services.google_traffic.client_id'), 'demo-verified-traffic');
    }

    /**
     * @return array{ga4: list<array<string, mixed>>, gsc: list<array<string, mixed>>}
     */
    private function demoProperties(Site $site): array
    {
        $host = \App\Support\VerifiedTraffic::registrableHost((string) ($site->domain ?: $site->site_url));

        return [
            'ga4' => [
                [
                    'id' => 'properties/111',
                    'name' => 'Verified Demo GA4',
                    'host' => $host,
                    'match' => true,
                ],
                [
                    'id' => 'properties/222',
                    'name' => 'Other property',
                    'host' => 'other.example',
                    'match' => false,
                ],
            ],
            'gsc' => [
                [
                    'id' => 'sc-domain:'.$host,
                    'name' => 'sc-domain:'.$host,
                    'host' => $host,
                    'match' => true,
                ],
                [
                    'id' => 'https://other.example/',
                    'name' => 'https://other.example/',
                    'host' => 'other.example',
                    'match' => false,
                ],
            ],
        ];
    }
}
