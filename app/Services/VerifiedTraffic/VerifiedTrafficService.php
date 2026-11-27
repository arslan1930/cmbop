<?php

namespace App\Services\VerifiedTraffic;

use App\Jobs\SyncSiteVerifiedTraffic;
use App\Mail\VerifiedTrafficSyncFailed;
use App\Models\InAppNotification;
use App\Models\Site;
use App\Models\SiteTrafficConnection;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Support\VerifiedTraffic;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Two\User as SocialiteUser;

class VerifiedTrafficService
{
    public function __construct(
        private readonly GoogleTrafficClient $google,
        private readonly InAppNotificationService $notifications,
    ) {}

    public function googleRedirect(Site $site, User $user): string
    {
        $state = $this->encodeOauthState($site->id, $user->id);

        return $this->google->oauthDriver($state)->redirect()->getTargetUrl();
    }

    public function startConnection(Site $site, User $user, SocialiteUser $googleUser): SiteTrafficConnection
    {
        $access = (string) ($googleUser->token ?? '');
        if ($access === '') {
            throw new GoogleTrafficException('Google did not return an access token', 401);
        }

        $refresh = $googleUser->refreshToken ?: null;
        $connection = SiteTrafficConnection::query()->firstOrNew(['site_id' => $site->id]);
        $connection->user_id = $user->id;
        $connection->google_account_email = $googleUser->getEmail();
        $connection->google_account_id = $googleUser->getId();
        $connection->setAccessToken($access);
        if ($refresh) {
            $connection->setRefreshToken($refresh);
        } elseif (! $connection->refresh_token_encrypted) {
            throw new GoogleTrafficException('Google did not return a refresh token. Reconnect and grant access again.', 401);
        }
        $expiresIn = (int) ($googleUser->expiresIn ?? 3600);
        $connection->token_expires_at = now()->addSeconds(max(60, $expiresIn - 60));
        $connection->scopes = VerifiedTraffic::SCOPES;
        $connection->status = VerifiedTraffic::STATUS_PENDING;
        $connection->last_error = null;
        $connection->save();

        $this->snapshotManualTraffic($site);

        return $connection;
    }

    /**
     * @return array{ga4: list<array<string, mixed>>, gsc: list<array<string, mixed>>}
     */
    public function listMatchingProperties(Site $site): array
    {
        $connection = $this->requireConnection($site);
        $token = $this->freshAccessToken($connection);
        $domain = (string) ($site->domain ?: $site->site_url);

        return [
            'ga4' => $this->google->listGa4Properties($token, $domain),
            'gsc' => $this->google->listGscSites($token, $domain),
        ];
    }

    public function saveProperties(Site $site, ?string $ga4PropertyId, ?string $gscSiteUrl): SiteTrafficConnection
    {
        $connection = $this->requireConnection($site);
        $token = $this->freshAccessToken($connection);
        $domain = (string) ($site->domain ?: $site->site_url);

        $ga4Id = filled($ga4PropertyId) ? (string) $ga4PropertyId : null;
        $gscUrl = filled($gscSiteUrl) ? (string) $gscSiteUrl : null;
        if ($ga4Id === null && $gscUrl === null) {
            throw new GoogleTrafficException('Pick a matching GA4 property, a Search Console property, or both.');
        }

        $ga4Name = null;
        if ($ga4Id !== null) {
            $matches = collect($this->google->listGa4Properties($token, $domain))
                ->first(fn ($row) => $row['id'] === $ga4Id && $row['match']);
            if (! $matches) {
                throw new GoogleTrafficException('That GA4 property does not match this site’s domain.');
            }
            $ga4Name = (string) $matches['name'];
        }

        if ($gscUrl !== null) {
            $matches = collect($this->google->listGscSites($token, $domain))
                ->first(fn ($row) => $row['id'] === $gscUrl && $row['match']);
            if (! $matches) {
                throw new GoogleTrafficException('That Search Console property does not match this site’s domain.');
            }
        }

        $connection->ga4_property_id = $ga4Id;
        $connection->ga4_property_name = $ga4Name;
        $connection->gsc_site_url = $gscUrl;
        $connection->status = VerifiedTraffic::STATUS_CONNECTED;
        $connection->last_error = null;
        $connection->save();

        SyncSiteVerifiedTraffic::dispatch($site->id);

        return $connection;
    }

    public function sync(Site $site): void
    {
        if (! VerifiedTraffic::schemaReady()) {
            return;
        }

        $connection = SiteTrafficConnection::query()->where('site_id', $site->id)->first();
        if (! $connection || ! $connection->hasProperty()) {
            return;
        }

        if ($site->archived_at || (! $site->active && ! $site->verified)) {
            // Still allow a first sync right after connect (pending listings).
            if ($connection->last_synced_at) {
                return;
            }
        }

        try {
            $token = $this->freshAccessToken($connection);
            $ga4 = null;
            $gsc = null;
            if (filled($connection->ga4_property_id)) {
                $report = $this->google->ga4Sessions($token, (string) $connection->ga4_property_id);
                $ga4 = (int) $report['sessions'];
            }
            if (filled($connection->gsc_site_url)) {
                $gsc = $this->google->gscClicks($token, (string) $connection->gsc_site_url);
            }

            $this->applyGoogleNumbers($site, $ga4, $gsc);
            $connection->status = VerifiedTraffic::STATUS_CONNECTED;
            $connection->last_error = null;
            $connection->last_synced_at = now();
            $connection->save();
        } catch (GoogleTrafficException $e) {
            $this->markFailure($site, $connection, $e->getMessage(), $e->isAuthFailure());
        } catch (\Throwable $e) {
            Log::error('Verified traffic sync failed', [
                'site_id' => $site->id,
                'exception' => $e::class,
            ]);
            $this->markFailure($site, $connection, 'Traffic sync failed. Reconnect Google if this continues.', false);
        }
    }

    public function disconnect(Site $site): void
    {
        if (! VerifiedTraffic::schemaReady()) {
            return;
        }

        $connection = SiteTrafficConnection::query()->where('site_id', $site->id)->first();
        if ($connection) {
            $this->google->revokeToken($connection->accessToken());
            $this->google->revokeToken($connection->refreshToken());
            $connection->delete();
        }

        $payload = [];
        if (Site::hasSitesColumn('traffic_manual') && $site->traffic_manual !== null) {
            $payload['traffic'] = (int) $site->traffic_manual;
        }
        if (Site::hasSitesColumn('traffic_source')) {
            $payload['traffic_source'] = VerifiedTraffic::SOURCE_MANUAL;
        }
        if (Site::hasSitesColumn('traffic_verified_at')) {
            $payload['traffic_verified_at'] = null;
        }
        if (Site::hasSitesColumn('traffic_ga4')) {
            $payload['traffic_ga4'] = null;
        }
        if (Site::hasSitesColumn('traffic_gsc_clicks')) {
            $payload['traffic_gsc_clicks'] = null;
        }
        if ($payload !== []) {
            $site->forceFill($payload)->save();
        }
    }

    public function applyAdminOverride(Site $site, ?int $override): void
    {
        if (! VerifiedTraffic::schemaReady()) {
            $site->traffic = max(0, (int) $override);
            $site->save();

            return;
        }

        $site->traffic_admin_override = $override;
        if ($override !== null) {
            $site->traffic = $override;
            $site->traffic_source = VerifiedTraffic::SOURCE_ADMIN;
            $site->traffic_verified_at = null;
        } else {
            $this->recomputeListingNumber($site);
        }
        $site->save();
    }

    public function rememberPublisherTraffic(Site $site, int $traffic): void
    {
        if (! VerifiedTraffic::schemaReady()) {
            return;
        }

        $site->traffic_manual = $traffic;
        if (! VerifiedTraffic::showsBadge($site) && $site->traffic_admin_override === null) {
            $site->traffic = $traffic;
            $site->traffic_source = VerifiedTraffic::SOURCE_MANUAL;
        }
        $site->save();
    }

    public function encodeOauthState(int $siteId, int $userId): string
    {
        $nonce = bin2hex(random_bytes(8));
        $payload = json_encode([
            'site_id' => $siteId,
            'user_id' => $userId,
            'nonce' => $nonce,
        ], JSON_THROW_ON_ERROR);
        session(['verified_traffic_oauth_nonce' => $nonce]);

        return Crypt::encryptString($payload);
    }

    /**
     * @return array{site_id: int, user_id: int}
     */
    public function decodeOauthState(?string $state): array
    {
        if (! filled($state)) {
            throw new GoogleTrafficException('Missing Google sign-in state.');
        }

        try {
            $raw = Crypt::decryptString($state);
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new GoogleTrafficException('Google sign-in state was invalid. Try connecting again.');
        }

        $nonce = (string) session('verified_traffic_oauth_nonce');
        session()->forget('verified_traffic_oauth_nonce');
        if ($nonce === '' || ! hash_equals($nonce, (string) ($data['nonce'] ?? ''))) {
            throw new GoogleTrafficException('Google sign-in expired. Try connecting again.');
        }

        return [
            'site_id' => (int) ($data['site_id'] ?? 0),
            'user_id' => (int) ($data['user_id'] ?? 0),
        ];
    }

    private function applyGoogleNumbers(Site $site, ?int $ga4, ?int $gsc): void
    {
        if (Site::hasSitesColumn('traffic_ga4')) {
            $site->traffic_ga4 = $ga4;
        }
        if (Site::hasSitesColumn('traffic_gsc_clicks')) {
            $site->traffic_gsc_clicks = $gsc;
        }

        if ($site->traffic_admin_override !== null) {
            $site->traffic = (int) $site->traffic_admin_override;
            $site->traffic_source = VerifiedTraffic::SOURCE_ADMIN;
            $site->traffic_verified_at = null;
            $site->save();

            return;
        }

        if ($ga4 !== null) {
            $site->traffic = $ga4;
            $site->traffic_source = VerifiedTraffic::SOURCE_GA4;
            $site->traffic_verified_at = now();
        } elseif ($gsc !== null) {
            $site->traffic = $gsc;
            $site->traffic_source = VerifiedTraffic::SOURCE_GSC;
            $site->traffic_verified_at = now();
        }

        $site->save();
    }

    private function recomputeListingNumber(Site $site): void
    {
        if ($site->traffic_ga4 !== null) {
            $site->traffic = (int) $site->traffic_ga4;
            $site->traffic_source = VerifiedTraffic::SOURCE_GA4;
            $connection = SiteTrafficConnection::query()->where('site_id', $site->id)->first();
            $site->traffic_verified_at = $connection?->last_synced_at;
            if ($site->traffic_verified_at) {
                return;
            }
        }
        if ($site->traffic_gsc_clicks !== null) {
            $site->traffic = (int) $site->traffic_gsc_clicks;
            $site->traffic_source = VerifiedTraffic::SOURCE_GSC;
            $connection = $connection ?? SiteTrafficConnection::query()->where('site_id', $site->id)->first();
            $site->traffic_verified_at = $connection?->last_synced_at;

            return;
        }
        if ($site->traffic_manual !== null) {
            $site->traffic = (int) $site->traffic_manual;
        }
        $site->traffic_source = VerifiedTraffic::SOURCE_MANUAL;
        $site->traffic_verified_at = null;
    }

    private function snapshotManualTraffic(Site $site): void
    {
        if (! Site::hasSitesColumn('traffic_manual')) {
            return;
        }
        $source = (string) ($site->traffic_source ?? VerifiedTraffic::SOURCE_MANUAL);
        if ($site->traffic_manual === null || $source === VerifiedTraffic::SOURCE_MANUAL) {
            $site->traffic_manual = (int) $site->traffic;
            $site->save();
        }
    }

    private function requireConnection(Site $site): SiteTrafficConnection
    {
        $connection = SiteTrafficConnection::query()->where('site_id', $site->id)->first();
        if (! $connection) {
            throw new GoogleTrafficException('Connect Google first.');
        }

        return $connection;
    }

    private function freshAccessToken(SiteTrafficConnection $connection): string
    {
        $token = $connection->accessToken();
        $expires = $connection->token_expires_at;
        $fresh = $token && $expires && $expires->isFuture();
        if ($fresh) {
            return $token;
        }

        $refresh = $connection->refreshToken();
        if (! $refresh) {
            throw new GoogleTrafficException('Google access was denied or revoked', 401);
        }

        $renewed = $this->google->refreshAccessToken($refresh);
        if (! $renewed) {
            throw new GoogleTrafficException('Google access was denied or revoked', 401);
        }

        $connection->setAccessToken($renewed['access_token']);
        $connection->token_expires_at = now()->addSeconds(max(60, $renewed['expires_in'] - 60));
        $connection->save();

        return $renewed['access_token'];
    }

    private function markFailure(Site $site, SiteTrafficConnection $connection, string $message, bool $authFailure): void
    {
        $connection->status = VerifiedTraffic::STATUS_ERROR;
        $connection->last_error = mb_substr($message, 0, 500);
        $connection->save();

        if (Site::hasSitesColumn('traffic_verified_at')) {
            $site->traffic_verified_at = null;
            $site->save();
        }

        if (! $connection->canNotifyFailure()) {
            return;
        }

        $publisher = User::query()->find($site->publisher_id);
        if (! $publisher) {
            return;
        }

        $this->notifications->notify(
            $publisher,
            InAppNotificationService::TYPE_SITE_STATUS,
            'Verified traffic needs attention — '.($site->site_name ?: $site->domain),
            $authFailure
                ? 'Google access for this site expired or was revoked. Reconnect Google Analytics / Search Console to keep the Verified traffic badge.'
                : 'We could not refresh verified traffic for this site. The listing still shows the last number. Reconnect Google if this continues.',
            [
                'category' => InAppNotificationService::CATEGORY_ACCOUNT,
                'icon' => 'alert-triangle',
                'related' => $site,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'Reconnect Google',
                'action_url' => route('publisher.sites.google.properties', $site, false),
                'meta' => ['site_id' => $site->id],
            ]
        );

        Mail::to($publisher->email)->send(new VerifiedTrafficSyncFailed($site, $publisher, $authFailure));

        $connection->last_notified_at = now();
        $connection->save();
    }
}
