<?php

namespace App\Services;

use App\Jobs\EnrichSiteJob;
use App\Mail\SiteStatusNotification;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SiteFileVerificationService
{
    public const FILE_NAME = 'seolinkbuildings-verify.txt';

    public const METHOD_FILE = 'file';

    public const METHOD_MANUAL = 'manual';

    public const ERROR_NOT_FOUND = 'not_found';

    public const ERROR_MISMATCH = 'mismatch';

    public const ERROR_UNREACHABLE = 'unreachable';

    public const ERROR_NOT_PUBLIC = 'not_public';

    public const ERROR_INCOMPLETE = 'incomplete';

    /**
     * Ensure the site has a verification token and return instructions payload.
     *
     * @return array{token: string, file_name: string, file_url: string, regenerated: bool}
     */
    public function start(Site $site, bool $regenerate = false): array
    {
        $regenerated = false;

        if ($regenerate || blank($site->verify_token)) {
            $site->verify_token = $this->generateToken();
            $site->verify_token_created_at = now();
            $site->save();
            $regenerated = true;
        }

        return [
            'token' => (string) $site->verify_token,
            'file_name' => self::FILE_NAME,
            'file_url' => $this->verificationFileUrl($site),
            'regenerated' => $regenerated,
        ];
    }

    /**
     * Fetch the public verification file and auto-verify on exact token match.
     *
     * @return array{success: bool, verified: bool, message: string, file_url: string, status?: int|null, error_code?: string|null}
     */
    public function check(Site $site): array
    {
        if ($site->verified) {
            return [
                'success' => true,
                'verified' => true,
                'message' => 'This website is already verified.',
                'file_url' => $this->verificationFileUrl($site),
                'error_code' => null,
            ];
        }

        if ($site->awaitsPublisherDetails()) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Finish required site details before requesting verification.',
                'file_url' => $this->verificationFileUrl($site),
                'error_code' => self::ERROR_INCOMPLETE,
            ];
        }

        if (blank($site->verify_token)) {
            $this->start($site);
            $site->refresh();
        }

        $fileUrl = $this->verificationFileUrl($site);
        $expected = $this->normalizeVerificationBody((string) $site->verify_token);
        $fetch = $this->fetchVerificationFile($site, $expected);

        if (! $fetch['ok']) {
            return [
                'success' => false,
                'verified' => false,
                'message' => $fetch['message'],
                'file_url' => $fileUrl,
                'status' => $fetch['status'] ?? null,
                'error_code' => $fetch['error_code'] ?? self::ERROR_NOT_FOUND,
            ];
        }

        $found = $this->normalizeVerificationBody((string) ($fetch['body'] ?? ''));

        if ($found !== $expected) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'We found the file, but the code does not match. Make sure the file contains only your verification code, then try again.',
                'file_url' => $fileUrl,
                'status' => $fetch['status'] ?? 200,
                'error_code' => self::ERROR_MISMATCH,
            ];
        }

        $this->markVerified($site, self::METHOD_FILE);

        return [
            'success' => true,
            'verified' => true,
            'message' => 'Website verified! Your Verified badge is now live on this listing.',
            'file_url' => $fileUrl,
            'error_code' => null,
        ];
    }

    public function markVerified(Site $site, string $method = self::METHOD_FILE): void
    {
        $site->verified = true;
        $site->verified_at = now();
        $site->verify_method = $method;
        $site->verify_token = null;
        $site->verify_token_created_at = null;
        $site->save();

        // Side effects must never undo a successful ownership proof for the publisher UI.
        try {
            ActivityLogger::log(
                'site.verified_'.$method,
                'Site "'.$site->site_name.'" verified via '.$method,
                $site,
                [
                    'method' => $method,
                    'domain' => $site->domain,
                ],
                $site->site_name
            );
        } catch (\Throwable $e) {
            Log::error('Failed to log site file verification', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if (config('site_enrichment.enabled', true)) {
                $runMetrics = ! (bool) $site->metrics_manual;
                EnrichSiteJob::dispatch($site->id, 'verify', $runMetrics, true);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to queue enrichment after file verification', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site->fresh(), 'verified'));
            }
            if ($publisher) {
                app(InAppNotificationService::class)->notifySiteStatusChanged($site->fresh(), 'verified');
            }
        } catch (\Throwable $e) {
            Log::error('Failed to notify publisher after file verification', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Strip BOM / zero-width chars and surrounding whitespace so Notepad / editor
     * uploads still match the expected token.
     */
    public function normalizeVerificationBody(string $body): string
    {
        $body = preg_replace('/^\xEF\xBB\xBF/u', '', $body) ?? $body;
        $body = str_replace("\u{FEFF}", '', $body);

        return trim($body);
    }

    /**
     * Preferred public URL shown in publisher instructions (always HTTPS when possible).
     */
    public function verificationFileUrl(Site $site): string
    {
        return 'https://'.$this->siteHost($site).'/'.self::FILE_NAME;
    }

    public function homepageBaseUrl(Site $site): string
    {
        return 'https://'.$this->siteHost($site);
    }

    public function generateToken(): string
    {
        return 'slb-verify-'.Str::lower(Str::random(24));
    }

    /**
     * Recheck unverified drafts that already have a verify_token.
     * Live (active) listings are skipped — the Verified badge is an admin
     * per-site choice once a site is in the catalog.
     *
     * @return array{checked: int, verified: int}
     */
    public function recheckPending(int $limit = 100): array
    {
        $checked = 0;
        $verified = 0;

        $sites = Site::query()
            ->where('verified', false)
            ->where(function ($q) {
                $q->where('active', 0)->orWhereNull('active');
            })
            ->whereNotNull('verify_token')
            ->where(function ($q) {
                $q->whereNull('onboarding_status')
                    ->orWhere('onboarding_status', '!=', Site::ONBOARDING_AWAITING_DETAILS);
            })
            ->orderBy('verify_token_created_at')
            ->limit(max(1, $limit))
            ->get();

        foreach ($sites as $site) {
            $checked++;
            $result = $this->check($site->fresh());
            if (! empty($result['verified'])) {
                $verified++;
            }
        }

        return [
            'checked' => $checked,
            'verified' => $verified,
        ];
    }

    /**
     * @return array{ok: bool, body?: string, message: string, status?: int|null, error_code?: string}
     */
    private function fetchVerificationFile(Site $site, ?string $expectedToken = null): array
    {
        $candidates = $this->candidateFileUrls($site);
        $attemptCount = 0;
        $exceptionCount = 0;
        $blockedCount = 0;
        $notFoundCount = 0;
        $lastBlockedStatus = null;
        $lastBlockedUrl = null;
        $firstReadable = null;

        foreach ($candidates as $url) {
            $attemptCount++;
            try {
                // Ownership proof only — do not fail publishers with incomplete TLS chains.
                $response = Http::timeout(15)
                    ->withoutVerifying()
                    ->withHeaders([
                        'User-Agent' => 'SEOLinkBuildings-SiteVerify/1.0',
                        'Accept' => 'text/plain,*/*',
                    ])
                    ->withOptions(['allow_redirects' => true])
                    ->get($url);

                if ($response->status() === 404) {
                    $notFoundCount++;

                    continue;
                }

                if (! $response->successful()) {
                    $blockedCount++;
                    $lastBlockedStatus = $response->status();
                    $lastBlockedUrl = $url;

                    continue;
                }

                $body = (string) $response->body();
                // Reject obvious HTML error/ fort pages pretending to be the file.
                if (str_contains(strtolower($body), '<html') || str_contains(strtolower($body), '<!doctype')) {
                    $blockedCount++;
                    $lastBlockedStatus = $response->status();
                    $lastBlockedUrl = $url;

                    continue;
                }

                $readable = [
                    'ok' => true,
                    'body' => $body,
                    'message' => 'File found.',
                    'status' => $response->status(),
                ];

                if ($expectedToken !== null
                    && $this->normalizeVerificationBody($body) === $expectedToken) {
                    return $readable;
                }

                // Keep scanning www / http alternates for an exact match before
                // treating a wrong apex body as a hard mismatch.
                $firstReadable ??= $readable;
            } catch (\Throwable $e) {
                $exceptionCount++;
                Log::info('Site file verification fetch failed', [
                    'site_id' => $site->id,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($firstReadable !== null) {
            return $firstReadable;
        }

        if ($attemptCount > 0 && $exceptionCount === $attemptCount) {
            return [
                'ok' => false,
                'message' => 'Site not reachable. We could not connect to '.$this->verificationFileUrl($site).'. Check the domain is online, then try again.',
                'status' => null,
                'error_code' => self::ERROR_UNREACHABLE,
            ];
        }

        if ($blockedCount > 0 && $notFoundCount === 0 && $exceptionCount < $attemptCount) {
            $hintUrl = $lastBlockedUrl ?: $this->verificationFileUrl($site);
            $statusHint = $lastBlockedStatus ? ' (HTTP '.$lastBlockedStatus.')' : '';

            return [
                'ok' => false,
                'message' => 'We could not read the verification file'.$statusHint.'. Make sure '.$hintUrl.' is publicly accessible (Cloudflare/bot protection must allow this file).',
                'status' => $lastBlockedStatus,
                'error_code' => self::ERROR_NOT_PUBLIC,
            ];
        }

        return [
            'ok' => false,
            'message' => 'Verification file not found. Upload '.self::FILE_NAME.' to your website root so it opens at '.$this->verificationFileUrl($site).' and try again.',
            'status' => 404,
            'error_code' => self::ERROR_NOT_FOUND,
        ];
    }

    /**
     * Always prefer HTTPS, then www alternate, then HTTP fallbacks.
     *
     * @return list<string>
     */
    private function candidateFileUrls(Site $site): array
    {
        $host = $this->siteHost($site);
        if ($host === '') {
            return [];
        }

        if (str_starts_with($host, 'www.')) {
            $altHost = substr($host, 4);
        } else {
            $altHost = 'www.'.$host;
        }

        $urls = [
            'https://'.$host.'/'.self::FILE_NAME,
            'https://'.$altHost.'/'.self::FILE_NAME,
            'http://'.$host.'/'.self::FILE_NAME,
            'http://'.$altHost.'/'.self::FILE_NAME,
        ];

        return array_values(array_unique($urls));
    }

    private function siteHost(Site $site): string
    {
        $url = trim((string) ($site->site_url ?: ''));
        if ($url === '') {
            $url = 'https://'.ltrim((string) $site->domain, '/');
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        if (! is_string($host) || $host === '') {
            $fallback = ltrim((string) $site->domain, '/');
            // Domain column sometimes stores path fragments — keep only the host-like piece.
            $pieces = preg_split('~[/?#]~', $fallback, 2) ?: [];
            $fallback = trim((string) ($pieces[0] ?? $fallback));
            $host = $fallback;
        }

        $host = strtolower(rtrim((string) $host, '.'));
        if ($host === '') {
            return '';
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        $port = $parts['port'] ?? null;
        if (is_int($port) && ! in_array($port, [80, 443], true)) {
            $host .= ':'.$port;
        }

        return $host;
    }
}
