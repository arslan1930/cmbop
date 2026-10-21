<?php

namespace App\Services\Advertiser;

use App\Models\AdvertiserWebhookDelivery;
use App\Models\AdvertiserWebhookEndpoint;
use App\Models\AdvertiserWebhookEvent;
use App\Models\User;
use App\Services\Api\WebhookSignature;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class AdvertiserWebhookService
{
    /** @var list<int> minutes between retries */
    public const RETRY_MINUTES = [1, 5, 15, 60, 180, 360, 720, 1440, 1440];

    public const MAX_ENDPOINTS = 10;

    /**
     * @param  array<string, mixed>  $data
     */
    public function emit(
        User $user,
        string $type,
        array $data,
        bool $livemode = true,
        ?AdvertiserWebhookEndpoint $only = null,
    ): ?AdvertiserWebhookEvent {
        if (! Schema::hasTable('advertiser_webhook_events')) {
            return null;
        }
        $payload = [
            'id' => null,
            'type' => $type,
            'api_version' => 'v1',
            'created_at' => now()->utc()->toIso8601String(),
            'livemode' => $livemode,
            'test_mode' => ! $livemode,
            'data' => $data,
        ];
        $event = AdvertiserWebhookEvent::query()->create([
            'user_id' => $user->id,
            'public_id' => 'evt_'.Str::lower(Str::random(12)),
            'type' => $type,
            'payload' => $payload,
            'livemode' => $livemode,
        ]);
        $payload = $event->payload;
        $payload['id'] = $event->public_id;
        $event->payload = $payload;
        $event->save();

        $endpoints = $only
            ? collect([$only])
            : AdvertiserWebhookEndpoint::query()
                ->where('user_id', $user->id)
                ->where('status', 'enabled')
                ->get();
        foreach ($endpoints as $endpoint) {
            if (! $endpoint->isEnabled() || ! $endpoint->matchesEvent($type)) {
                continue;
            }
            AdvertiserWebhookDelivery::query()->create([
                'event_id' => $event->id,
                'endpoint_id' => $endpoint->id,
                'attempt' => 0,
                'status' => 'pending',
                'next_attempt_at' => now(),
            ]);
        }
        $this->deliverDue(20);

        return $event->fresh();
    }

    public function sendTest(AdvertiserWebhookEndpoint $endpoint): ?AdvertiserWebhookEvent
    {
        $user = $endpoint->user ?: User::query()->find($endpoint->user_id);
        if (! $user) {
            return null;
        }

        return $this->emit($user, 'webhook.test', [
            'object' => [
                'id' => 'wh_'.$endpoint->id,
                'message' => 'This is a test event from SEOLinkBuildings.',
            ],
        ], false, $endpoint);
    }

    /**
     * @param  list<string>  $events
     * @return array{endpoint: AdvertiserWebhookEndpoint, secret: string}
     */
    public function createEndpoint(User $user, string $url, array $events = ['*'], ?string $description = null): array
    {
        $count = AdvertiserWebhookEndpoint::query()->where('user_id', $user->id)->count();
        $max = (int) config('advertiser_api.max_webhooks', self::MAX_ENDPOINTS);
        if ($count >= $max) {
            throw new RuntimeException('An advertiser may have at most '.$max.' webhook endpoints.');
        }
        $allowed = config('advertiser_api.events', []);
        $events = $events !== [] ? array_values($events) : ['*'];
        foreach ($events as $event) {
            if ($event !== '*' && $event !== 'order.*' && ! in_array($event, $allowed, true)) {
                throw new RuntimeException('Unknown webhook event: '.$event);
            }
        }
        $secret = 'whsec_'.Str::lower(Str::random(32));
        $endpoint = AdvertiserWebhookEndpoint::query()->create([
            'user_id' => $user->id,
            'url' => $url,
            'events' => $events,
            'secret_encrypted' => Crypt::encryptString($secret),
            'status' => 'enabled',
            'description' => $description,
        ]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    /**
     * @param  array{url?: string, events?: list<string>, status?: string, description?: ?string}  $data
     */
    public function updateEndpoint(AdvertiserWebhookEndpoint $endpoint, array $data): AdvertiserWebhookEndpoint
    {
        if (isset($data['url'])) {
            $endpoint->url = (string) $data['url'];
        }
        if (array_key_exists('events', $data) && is_array($data['events']) && $data['events'] !== []) {
            $endpoint->events = array_values($data['events']);
        }
        if (isset($data['description'])) {
            $endpoint->description = $data['description'];
        }
        if (($data['status'] ?? null) === 'disabled') {
            $endpoint->status = 'disabled';
            $endpoint->disabled_at = now();
        } elseif (($data['status'] ?? null) === 'enabled') {
            $endpoint->status = 'enabled';
            $endpoint->disabled_at = null;
            $endpoint->failure_streak = 0;
        }
        $endpoint->save();

        return $endpoint->fresh();
    }

    public function deleteEndpoint(AdvertiserWebhookEndpoint $endpoint): void
    {
        AdvertiserWebhookDelivery::query()->where('endpoint_id', $endpoint->id)->delete();
        $endpoint->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeEndpoint(AdvertiserWebhookEndpoint $endpoint, ?string $secret = null): array
    {
        $payload = [
            'id' => 'wh_'.$endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events ?? ['*'],
            'status' => $endpoint->status,
            'description' => $endpoint->description,
            'created_at' => optional($endpoint->created_at)?->toIso8601String(),
        ];
        if ($secret !== null) {
            $payload['secret'] = $secret;
        }

        return $payload;
    }

    public function deliverDue(int $limit = 50, int $maxSeconds = 0): int
    {
        if (! Schema::hasTable('advertiser_webhook_deliveries')) {
            return 0;
        }
        $rows = AdvertiserWebhookDelivery::query()
            ->with(['event', 'endpoint'])
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $deadline = $maxSeconds > 0 ? microtime(true) + $maxSeconds : null;
        $done = 0;
        foreach ($rows as $row) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }
            $this->attempt($row);
            $done++;
        }

        return $done;
    }

    public function attempt(AdvertiserWebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint;
        $event = $delivery->event;
        if (! $endpoint || ! $event) {
            $delivery->status = 'failed';
            $delivery->error = 'missing_endpoint_or_event';
            $delivery->save();

            return;
        }
        $body = json_encode($event->payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $secret = $endpoint->plainSecret();
        $signature = WebhookSignature::sign($secret, $timestamp, $body);
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'SLB-Webhook-Id' => (string) $event->public_id,
                    'SLB-Webhook-Timestamp' => $timestamp,
                    'SLB-Signature' => $signature,
                    'Content-Type' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
            $code = $response->status();
            $delivery->attempt = (int) $delivery->attempt + 1;
            $delivery->response_code = $code;
            if ($code >= 200 && $code < 300) {
                $delivery->status = 'succeeded';
                $delivery->delivered_at = now();
                $delivery->error = null;
                $endpoint->failure_streak = 0;
                $endpoint->save();
            } else {
                $this->scheduleRetry($delivery, $endpoint, 'http_'.$code);
            }
            $delivery->save();
        } catch (\Throwable $e) {
            $delivery->attempt = (int) $delivery->attempt + 1;
            $this->scheduleRetry($delivery, $endpoint, $e->getMessage());
            $delivery->save();
        }
    }

    public function sign(string $secret, string $timestamp, string $body): string
    {
        return WebhookSignature::sign($secret, $timestamp, $body);
    }

    private function scheduleRetry(AdvertiserWebhookDelivery $delivery, AdvertiserWebhookEndpoint $endpoint, string $error): void
    {
        $attempt = (int) $delivery->attempt;
        $delivery->error = $error;
        if ($attempt >= count(self::RETRY_MINUTES)) {
            $delivery->status = 'failed';
            $endpoint->failure_streak = (int) $endpoint->failure_streak + 1;
            if ($endpoint->failure_streak >= 3) {
                $endpoint->status = 'disabled';
                $endpoint->disabled_at = now();
            }
            $endpoint->save();

            return;
        }
        $minutes = self::RETRY_MINUTES[max(0, $attempt - 1)] ?? 1440;
        $delivery->status = 'pending';
        $delivery->next_attempt_at = now()->addMinutes($minutes);
    }
}
