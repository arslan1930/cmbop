<?php

namespace App\Services;

use App\Support\UserMessages;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayPal Orders v2 — create, capture, refund, and verify webhooks.
 *
 * Amounts always come from PayPal's payload after capture/refund, never from
 * a client query string. Fail closed when the kill switch is off or credentials
 * are missing.
 */
class PaypalCheckoutService
{
    public const TYPE_ORDER_CHECKOUT = 'order_checkout';

    public const TYPE_WALLET_DEPOSIT = 'wallet_deposit';

    public const CURRENCY = 'EUR';

    public function configured(): bool
    {
        if ($this->isExplicitlyDisabled()) {
            return false;
        }

        $id = $this->clientId();
        $secret = $this->secret();

        return $id !== ''
            && $secret !== ''
            && ! str_contains(strtolower($id), 'your-')
            && ! str_contains(strtolower($secret), 'your-');
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) config('services.paypal.mode', 'sandbox')));

        return $mode === 'live' ? 'live' : 'sandbox';
    }

    /**
     * Operator snapshot for `paypal:status`. Never includes the secret.
     *
     * @return array{mode: string, host: string, configured: bool, client_id_set: bool, secret_set: bool, webhook_id_set: bool, client_id_hint: string, secret_length: int}
     */
    public function connectionSnapshot(): array
    {
        $id = $this->clientId();
        $secret = $this->secret();
        $webhook = $this->normalizedCredential((string) config('services.paypal.webhook_id', ''));

        return [
            'mode' => $this->mode(),
            'host' => $this->baseUrl(),
            'configured' => $this->configured(),
            'client_id_set' => $id !== '',
            'secret_set' => $secret !== '',
            'webhook_id_set' => $webhook !== '',
            'client_id_hint' => $id === '' ? '' : substr($id, 0, 6).'… ('.strlen($id).' chars)',
            'secret_length' => strlen($secret),
        ];
    }

    public function baseUrl(): string
    {
        $override = rtrim(trim((string) config('services.paypal.base_url', '')), '/');
        if ($override !== '') {
            return $override;
        }

        return $this->mode() === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public static function formatEuros(float|int|string $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    public static function customId(string $type, int|string $userId, string $referenceCode): string
    {
        return $type.':'.$userId.':'.$referenceCode;
    }

    /**
     * @return array{type: string, user_id: string, reference_code: string}
     */
    public static function parseCustomId(?string $customId): array
    {
        $parts = explode(':', (string) $customId, 3);

        return [
            'type' => $parts[0] ?? '',
            'user_id' => $parts[1] ?? '',
            'reference_code' => $parts[2] ?? '',
        ];
    }

    /**
     * @param  array{type?: string, user_id: int|string, reference_code: string}  $meta
     * @return array{id: string, status: string, approve_url: string, amount: string, currency: string, raw: array<string, mixed>}
     */
    public function createOrder(float $euros, array $meta, string $returnUrl, string $cancelUrl): array
    {
        $this->assertConfigured();

        $amount = self::formatEuros($euros);
        if ((float) $amount < 0.01) {
            throw new RuntimeException('PayPal amount must be greater than €0.');
        }

        $type = (string) ($meta['type'] ?? self::TYPE_ORDER_CHECKOUT);
        $userId = trim((string) ($meta['user_id'] ?? ''));
        $reference = trim((string) ($meta['reference_code'] ?? ''));
        if ($userId === '' || $reference === '') {
            throw new RuntimeException('PayPal order meta requires user_id and reference_code.');
        }
        if (! in_array($type, [self::TYPE_ORDER_CHECKOUT, self::TYPE_WALLET_DEPOSIT], true)) {
            throw new RuntimeException('PayPal order type is not allowed.');
        }

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => self::CURRENCY,
                    'value' => $amount,
                ],
                'custom_id' => self::customId($type, $userId, $reference),
                'invoice_id' => $reference,
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'brand_name' => mb_substr((string) config('app.name', 'SEOLinkBuildings'), 0, 127),
            ],
        ];

        $response = $this->paypalRequest('post', '/v2/checkout/orders', $payload, [
            'PayPal-Request-Id' => 'create-'.$reference,
        ]);
        $data = $response->json() ?? [];
        $orderId = (string) ($data['id'] ?? '');
        $approveUrl = $this->linkHref($data, 'approve');

        if ($orderId === '' || $approveUrl === '') {
            throw new RuntimeException('PayPal did not return an approve link.');
        }

        return [
            'id' => $orderId,
            'status' => (string) ($data['status'] ?? ''),
            'approve_url' => $approveUrl,
            'amount' => $amount,
            'currency' => self::CURRENCY,
            'raw' => $data,
        ];
    }

    /**
     * Capture a buyer-approved order. Amount is taken from PayPal, not the client.
     *
     * @return array{id: string, capture_id: string, status: string, amount: float, currency: string, custom: array{type: string, user_id: string, reference_code: string}, raw: array<string, mixed>}
     */
    public function captureOrder(string $paypalOrderId): array
    {
        $this->assertConfigured();

        $paypalOrderId = trim($paypalOrderId);
        if ($paypalOrderId === '') {
            throw new RuntimeException('Missing PayPal order id.');
        }

        $response = $this->paypalRequest(
            'post',
            '/v2/checkout/orders/'.rawurlencode($paypalOrderId).'/capture',
            new \stdClass,
            ['PayPal-Request-Id' => 'capture-'.$paypalOrderId],
            false
        );
        if ($response->successful()) {
            return $this->completedCaptureFromPayload($response->json() ?? [], $paypalOrderId);
        }
        if ($this->isAlreadyCaptured($response)) {
            return $this->getCompletedCapture($paypalOrderId);
        }

        Log::error('PayPal API error', [
            'path' => '/v2/checkout/orders/'.$paypalOrderId.'/capture',
            'status' => $response->status(),
        ]);
        throw new RuntimeException('PayPal request failed.');
    }

    /**
     * @return array{id: string, capture_id: string, status: string, amount: float, currency: string, custom: array{type: string, user_id: string, reference_code: string}, raw: array<string, mixed>}
     */
    public function getCompletedCapture(string $paypalOrderId): array
    {
        $this->assertConfigured();

        $paypalOrderId = trim($paypalOrderId);
        if ($paypalOrderId === '') {
            throw new RuntimeException('Missing PayPal order id.');
        }

        $response = $this->paypalRequest(
            'get',
            '/v2/checkout/orders/'.rawurlencode($paypalOrderId)
        );

        return $this->completedCaptureFromPayload($response->json() ?? [], $paypalOrderId);
    }

    /**
     * @return array{id: string, status: string, amount: float, currency: string, raw: array<string, mixed>}
     */
    public function refundCapture(string $captureId, float $euros, ?string $requestId = null): array
    {
        $this->assertConfigured();

        $captureId = trim($captureId);
        $amount = self::formatEuros($euros);
        if ($captureId === '') {
            throw new RuntimeException('Missing PayPal capture id.');
        }
        if ((float) $amount < 0.01) {
            throw new RuntimeException('PayPal refund amount must be greater than €0.');
        }

        $idempotency = trim((string) $requestId);
        if ($idempotency === '') {
            $idempotency = 'refund-'.$captureId.'-'.$amount;
        }

        $response = $this->paypalRequest(
            'post',
            '/v2/payments/captures/'.rawurlencode($captureId).'/refund',
            [
                'amount' => [
                    'currency_code' => self::CURRENCY,
                    'value' => $amount,
                ],
            ],
            ['PayPal-Request-Id' => $idempotency],
            false
        );
        $data = $response->json() ?? [];
        if ($response->successful()) {
            $refundId = trim((string) ($data['id'] ?? ''));
            $status = strtoupper((string) ($data['status'] ?? ''));
            $refundedRaw = $data['amount']['value'] ?? $amount;

            if ($refundId === '') {
                throw new RuntimeException('PayPal refund did not return an id.');
            }

            return [
                'id' => $refundId,
                'status' => $status,
                'amount' => round((float) $refundedRaw, 2),
                'currency' => self::CURRENCY,
                'raw' => $data,
            ];
        }

        if ($this->isAlreadyRefunded($response)) {
            $existingId = trim((string) ($data['id'] ?? ''));

            return [
                'id' => $existingId !== '' ? $existingId : 'already-'.$captureId,
                'status' => 'COMPLETED',
                'amount' => (float) $amount,
                'currency' => self::CURRENCY,
                'raw' => $data,
            ];
        }

        Log::error('PayPal API error', [
            'path' => '/v2/payments/captures/'.$captureId.'/refund',
            'status' => $response->status(),
        ]);
        throw new RuntimeException('PayPal request failed.');
    }

    /**
     * Verify a PayPal webhook. Missing headers / bad cert host / FAILURE → not verified.
     *
     * @return array{verified: bool, event: array<string, mixed>, verification_status?: string, reason?: string}
     */
    public function verifyWebhook(Request $request): array
    {
        $this->assertConfigured();

        $webhookId = trim((string) config('services.paypal.webhook_id', ''));
        if ($webhookId === '') {
            throw new RuntimeException('PayPal webhook is not configured.');
        }

        $algo = (string) $request->header('PAYPAL-AUTH-ALGO', '');
        $certUrl = (string) $request->header('PAYPAL-CERT-URL', '');
        $transmissionId = (string) $request->header('PAYPAL-TRANSMISSION-ID', '');
        $signature = (string) $request->header('PAYPAL-TRANSMISSION-SIG', '');
        $transmissionTime = (string) $request->header('PAYPAL-TRANSMISSION-TIME', '');

        if ($algo === '' || $certUrl === '' || $transmissionId === '' || $signature === '' || $transmissionTime === '') {
            return ['verified' => false, 'event' => [], 'reason' => 'missing_headers'];
        }

        if (! $this->isPaypalCertUrl($certUrl)) {
            return ['verified' => false, 'event' => [], 'reason' => 'invalid_cert_url'];
        }

        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            return ['verified' => false, 'event' => [], 'reason' => 'invalid_body'];
        }

        $response = $this->paypalRequest('post', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $algo,
            'cert_url' => $certUrl,
            'transmission_id' => $transmissionId,
            'transmission_sig' => $signature,
            'transmission_time' => $transmissionTime,
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);

        $status = strtoupper((string) ($response->json('verification_status') ?? ''));

        return [
            'verified' => $status === 'SUCCESS',
            'event' => $event,
            'verification_status' => $status,
        ];
    }

    /**
     * Turn a verified PayPal webhook into the same capture payload finalize uses.
     * CHECKOUT.ORDER.APPROVED captures (or loads an already-captured order).
     *
     * @param  array<string, mixed>  $event
     * @return array{id: string, capture_id: string, status: string, amount: float, currency: string, custom: array{type: string, user_id: string, reference_code: string}, raw: array<string, mixed>}|null
     */
    public function captureFromWebhookEvent(array $event): ?array
    {
        $type = strtoupper((string) ($event['event_type'] ?? ''));
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        if ($resource === []) {
            return null;
        }

        if ($type === 'CHECKOUT.ORDER.APPROVED') {
            $orderId = trim((string) ($resource['id'] ?? ''));

            return $orderId !== '' ? $this->captureOrder($orderId) : null;
        }

        if ($type === 'CHECKOUT.ORDER.COMPLETED') {
            $orderId = trim((string) ($resource['id'] ?? ''));
            if ($orderId === '') {
                return null;
            }
            try {
                return $this->completedCaptureFromPayload($resource, $orderId);
            } catch (RuntimeException) {
                return $this->getCompletedCapture($orderId);
            }
        }

        if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
            return $this->completedCaptureFromCaptureResource($resource, $event);
        }

        return null;
    }

    /**
     * custom_id from a capture, refund, or denial webhook. Empty user/ref when missing.
     *
     * @param  array<string, mixed>  $event
     * @return array{type: string, user_id: string, reference_code: string}
     */
    public function customFromWebhookEvent(array $event): array
    {
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $unit = is_array($resource['purchase_units'][0] ?? null) ? $resource['purchase_units'][0] : [];
        $customId = $resource['custom_id']
            ?? $unit['custom_id']
            ?? $resource['supplementary_data']['related_ids']['custom_id']
            ?? '';

        return self::parseCustomId(is_string($customId) ? $customId : '');
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{refund_id: string, capture_id: string, paypal_order_id: string, amount: float, custom: array{type: string, user_id: string, reference_code: string}}|null
     */
    public function refundFromWebhookEvent(array $event): ?array
    {
        $type = strtoupper((string) ($event['event_type'] ?? ''));
        if ($type !== 'PAYMENT.CAPTURE.REFUNDED') {
            return null;
        }

        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $related = is_array($resource['supplementary_data']['related_ids'] ?? null)
            ? $resource['supplementary_data']['related_ids']
            : [];
        $refundId = trim((string) ($resource['id'] ?? ''));
        $captureId = trim((string) ($related['capture_id'] ?? $resource['capture_id'] ?? ''));
        $paypalOrderId = trim((string) ($related['order_id'] ?? ''));
        $custom = self::parseCustomId(isset($resource['custom_id']) ? (string) $resource['custom_id'] : '');
        $amountRaw = $resource['amount']['value'] ?? $resource['seller_payable_breakdown']['total_refunded_amount']['value'] ?? null;
        $amount = $amountRaw !== null && $amountRaw !== '' ? round((float) $amountRaw, 2) : 0.0;

        if ($refundId === '' || ($captureId === '' && $paypalOrderId === '')) {
            return null;
        }

        return [
            'refund_id' => $refundId,
            'capture_id' => $captureId,
            'paypal_order_id' => $paypalOrderId,
            'amount' => $amount,
            'custom' => $custom,
        ];
    }

    public function accessToken(): string
    {
        $this->assertConfigured();

        $cacheKey = 'paypal:oauth:'.$this->mode().':'.hash('sha256', $this->clientId()."\0".$this->secret());
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->requestAccessToken($this->baseUrl());

        if (! $response->successful()) {
            $paypalError = $this->safePaypalErrorCode($response);
            Log::error('PayPal OAuth failed', array_filter([
                'status' => $response->status(),
                'mode' => $this->mode(),
                'host' => $this->baseUrl(),
                'error' => $paypalError,
            ], fn ($value) => $value !== null && $value !== ''));
            throw new RuntimeException($this->oauthFailureMessage((int) $response->status()));
        }

        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new RuntimeException(UserMessages::get('payment.paypal_unavailable'));
        }

        $ttl = max(30, ((int) $response->json('expires_in', 300)) - 60);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    private function paypalRequest(string $method, string $path, mixed $body = null, array $headers = [], bool $throw = true): Response
    {
        $pending = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->withHeaders($headers);

        $url = $this->baseUrl().$path;
        $response = match (strtolower($method)) {
            'post' => $pending->post($url, $body ?? new \stdClass),
            'get' => $pending->get($url),
            default => throw new RuntimeException('Unsupported PayPal method.'),
        };

        if ($throw && ! $response->successful()) {
            Log::error('PayPal API error', [
                'path' => $path,
                'status' => $response->status(),
            ]);
            throw new RuntimeException('PayPal request failed.');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $event
     * @return array{id: string, capture_id: string, status: string, amount: float, currency: string, custom: array{type: string, user_id: string, reference_code: string}, raw: array<string, mixed>}
     */
    private function completedCaptureFromCaptureResource(array $resource, array $event): array
    {
        $related = is_array($resource['supplementary_data']['related_ids'] ?? null)
            ? $resource['supplementary_data']['related_ids']
            : [];
        $paypalOrderId = trim((string) ($related['order_id'] ?? ''));
        $custom = self::parseCustomId(isset($resource['custom_id']) ? (string) $resource['custom_id'] : '');

        return $this->completedCaptureFromPayload([
            'id' => $paypalOrderId,
            'status' => $resource['status'] ?? 'COMPLETED',
            'purchase_units' => [[
                'custom_id' => $resource['custom_id'] ?? '',
                'amount' => $resource['amount'] ?? [],
                'payments' => [
                    'captures' => [[
                        'id' => $resource['id'] ?? '',
                        'status' => $resource['status'] ?? '',
                        'amount' => $resource['amount'] ?? [],
                    ]],
                ],
            ]],
            'custom' => $custom,
            'event' => $event,
        ], $paypalOrderId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, capture_id: string, status: string, amount: float, currency: string, custom: array{type: string, user_id: string, reference_code: string}, raw: array<string, mixed>}
     */
    private function completedCaptureFromPayload(array $data, string $paypalOrderId): array
    {
        $unit = is_array($data['purchase_units'][0] ?? null) ? $data['purchase_units'][0] : [];
        $capture = is_array($unit['payments']['captures'][0] ?? null) ? $unit['payments']['captures'][0] : [];
        $captureId = trim((string) ($capture['id'] ?? ''));
        $status = strtoupper((string) ($capture['status'] ?? $data['status'] ?? ''));
        $amountRaw = $capture['amount']['value'] ?? $unit['amount']['value'] ?? null;
        $currency = (string) ($capture['amount']['currency_code'] ?? $unit['amount']['currency_code'] ?? self::CURRENCY);
        $custom = self::parseCustomId(isset($unit['custom_id']) ? (string) $unit['custom_id'] : '');

        if ($captureId === '' || $amountRaw === null || $amountRaw === '') {
            throw new RuntimeException('PayPal capture did not return an amount.');
        }
        if ($status !== 'COMPLETED') {
            throw new RuntimeException('PayPal capture was not completed.');
        }
        if (strtoupper($currency) !== self::CURRENCY) {
            throw new RuntimeException('PayPal capture currency is not EUR.');
        }
        if (($custom['user_id'] ?? '') === '') {
            throw new RuntimeException('PayPal capture is missing user_id.');
        }

        return [
            'id' => (string) ($data['id'] ?? $paypalOrderId),
            'capture_id' => $captureId,
            'status' => $status,
            'amount' => round((float) $amountRaw, 2),
            'currency' => self::CURRENCY,
            'custom' => $custom,
            'raw' => $data,
        ];
    }

    private function isAlreadyRefunded(Response $response): bool
    {
        $name = strtoupper((string) ($response->json('name') ?? ''));
        if (in_array($name, ['CAPTURE_FULLY_REFUNDED', 'CAPTURE_ALREADY_REFUNDED'], true)) {
            return true;
        }

        foreach ($response->json('details') ?? [] as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $issue = strtoupper((string) ($detail['issue'] ?? ''));
            if (in_array($issue, ['CAPTURE_FULLY_REFUNDED', 'CAPTURE_ALREADY_REFUNDED'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isAlreadyCaptured(Response $response): bool
    {
        $name = strtoupper((string) ($response->json('name') ?? ''));
        if ($name === 'ORDER_ALREADY_CAPTURED') {
            return true;
        }

        foreach ($response->json('details') ?? [] as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $issue = strtoupper((string) ($detail['issue'] ?? ''));
            if ($issue === 'ORDER_ALREADY_CAPTURED') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function linkHref(array $payload, string $rel): string
    {
        foreach ($payload['links'] ?? [] as $link) {
            if (! is_array($link)) {
                continue;
            }
            if (($link['rel'] ?? '') === $rel && ! empty($link['href'])) {
                return (string) $link['href'];
            }
        }

        return '';
    }

    private function isPaypalCertUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        return $host === 'paypal.com' || str_ends_with($host, '.paypal.com');
    }

    /**
     * Unset / true leave the rail on when credentials exist.
     * false, 0, off, no are an explicit kill switch.
     */
    private function isExplicitlyDisabled(): bool
    {
        $enabled = config('services.paypal.enabled');
        if ($enabled === null || $enabled === '') {
            return false;
        }
        if (is_bool($enabled)) {
            return $enabled === false;
        }

        $normalized = strtolower(trim((string) $enabled));

        return in_array($normalized, ['false', '0', 'off', 'no'], true);
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('PayPal is not configured.');
        }
    }

    private function clientId(): string
    {
        return $this->normalizedCredential((string) config('services.paypal.client_id', ''));
    }

    private function secret(): string
    {
        return $this->normalizedCredential((string) config('services.paypal.secret', ''));
    }

    /**
     * Dashboard copy-paste often wraps keys in quotes or includes a BOM /
     * non-breaking space. Those characters survive trim() and make PayPal
     * return HTTP 401.
     */
    private function normalizedCredential(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = str_replace(["\r", "\n", "\t", "\0", "\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $value);
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                $value = preg_replace('/\s+/', '', $value) ?? $value;
            }
        }

        return $value;
    }

    private function requestAccessToken(string $host): Response
    {
        return Http::asForm()
            ->acceptJson()
            ->withHeaders(['Accept-Language' => 'en_US'])
            ->timeout(15)
            ->withBasicAuth($this->clientId(), $this->secret())
            ->post(rtrim($host, '/').'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);
    }

    private function counterpartHost(): ?string
    {
        if (rtrim(trim((string) config('services.paypal.base_url', '')), '/') !== '') {
            return null;
        }

        return $this->mode() === 'live'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    private function oauthFailureMessage(int $status): string
    {
        if ($status !== 401) {
            return UserMessages::get('payment.paypal_unavailable');
        }

        $otherHost = $this->counterpartHost();
        if ($otherHost !== null) {
            try {
                $other = $this->requestAccessToken($otherHost);
                $token = trim((string) $other->json('access_token'));
                if ($other->successful() && $token !== '') {
                    $workingMode = $this->mode() === 'live' ? 'sandbox' : 'live';
                    Log::error('PayPal OAuth keys match the other environment', [
                        'mode' => $this->mode(),
                        'working_mode' => $workingMode,
                    ]);

                    return UserMessages::get(
                        $workingMode === 'live'
                            ? 'payment.paypal_auth_live_keys'
                            : 'payment.paypal_auth_sandbox_keys'
                    );
                }
            } catch (\Throwable) {
                // Keep the generic 401 copy if the counterpart host is unreachable.
            }
        }

        return UserMessages::get('payment.paypal_auth');
    }

    private function safePaypalErrorCode(Response $response): string
    {
        $code = strtolower(trim((string) ($response->json('error') ?? $response->json('name') ?? '')));
        if ($code === '' || strlen($code) > 64 || preg_match('/[^a-z0-9._-]/', $code)) {
            return '';
        }

        return $code;
    }
}
