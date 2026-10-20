<?php

namespace App\Services\Partners;

use App\Models\Partner;
use App\Models\PartnerWebhookDelivery;
use App\Models\PartnerWebhookEndpoint;
use App\Models\PartnerWebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PartnerWebhookService
{
    /** @var list<int> minutes between retries */
    public const RETRY_MINUTES = [1, 5, 15, 60, 180, 360, 720, 1440, 1440];

    /**
     * @param  array<string, mixed>  $data
     */
    public function emit(Partner $partner, string $type, array $data): ?PartnerWebhookEvent
    {
        if (! Schema::hasTable('partner_webhook_events')) {
            return null;
        }
        $event = PartnerWebhookEvent::query()->create([
            'partner_id' => $partner->id,
            'public_id' => 'evt_'.Str::lower(Str::random(12)),
            'type' => $type,
            'payload' => [
                'id' => null,
                'type' => $type,
                'api_version' => 'v1',
                'created_at' => now()->utc()->toIso8601String(),
                'test_mode' => false,
                'data' => $data,
            ],
        ]);
        $payload = $event->payload;
        $payload['id'] = $event->public_id;
        $event->payload = $payload;
        $event->save();

        $endpoints = PartnerWebhookEndpoint::query()
            ->where('partner_id', $partner->id)
            ->where('status', 'enabled')
            ->get();
        foreach ($endpoints as $endpoint) {
            if (! $endpoint->matchesEvent($type)) {
                continue;
            }
            PartnerWebhookDelivery::query()->create([
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

    public function deliverDue(int $limit = 50): int
    {
        if (! Schema::hasTable('partner_webhook_deliveries')) {
            return 0;
        }
        $rows = PartnerWebhookDelivery::query()
            ->with(['event', 'endpoint'])
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $done = 0;
        foreach ($rows as $row) {
            $this->attempt($row);
            $done++;
        }

        return $done;
    }

    public function attempt(PartnerWebhookDelivery $delivery): void
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
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
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
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function scheduleRetry(PartnerWebhookDelivery $delivery, PartnerWebhookEndpoint $endpoint, string $error): void
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
