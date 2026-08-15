<?php

namespace App\Support;

use App\Models\EmailLog;
use Carbon\Carbon;

class MailJobPayload
{
    public static function isQueuedMailable(string $payload): bool
    {
        return str_contains($payload, 'SendQueuedMailable');
    }

    public static function containsMailable(string $payload, string $class): bool
    {
        if ($class === '') {
            return false;
        }

        return str_contains($payload, $class)
            || str_contains($payload, str_replace('\\', '\\\\', $class));
    }

    /**
     * Database-queue payloads JSON-escape the serialized command, so
     * `campaignId";i:12;` does not appear as a literal. `i:12;` must not
     * match campaign 123.
     */
    public static function containsSendCampaignJob(string $payload, int $campaignId): bool
    {
        if ($campaignId < 1 || ! str_contains($payload, 'SendEmailCampaignJob')) {
            return false;
        }

        return self::containsCampaignId($payload, $campaignId);
    }

    /**
     * Match a recipient or dedupe key without treating "welcome:1" as "welcome:10".
     */
    public static function containsToken(string $payload, string $token): bool
    {
        $token = trim($token);
        if ($token === '' || strcasecmp($token, 'unknown') === 0) {
            return false;
        }

        if (str_contains($payload, json_encode($token, JSON_UNESCAPED_SLASHES))) {
            return true;
        }

        return str_contains($payload, 's:'.strlen($token).':"'.$token.'"');
    }

    public static function looksIdentified(string $payload): bool
    {
        if (str_contains($payload, 'dedupe_key') || str_contains($payload, 'dedupeKey')) {
            return true;
        }

        return self::emails($payload) !== [];
    }

    public static function matchesEmailLog(string $payload, EmailLog $log): bool
    {
        if (! self::isQueuedMailable($payload)) {
            return false;
        }

        $catalog = EmailCatalog::get((string) $log->template_key) ?? [];
        $class = (string) ($log->mailable ?: ($catalog['mailable'] ?? ''));
        if ($class !== '' && ! self::containsMailable($payload, $class)) {
            return false;
        }

        if (self::containsToken($payload, (string) $log->to_email)
            || self::containsToken($payload, (string) $log->dedupe_key)) {
            return true;
        }

        $to = (string) $log->to_email;
        $dedupe = (string) $log->dedupe_key;
        $logHasIdentity = ($to !== '' && strcasecmp($to, 'unknown') !== 0) || $dedupe !== '';

        return ! ($logHasIdentity && self::looksIdentified($payload));
    }

    /**
     * Match campaign 12 without treating i:123; or "campaignId":123 as a hit.
     * Database-queue rows JSON-escape the serialized command.
     */
    public static function containsCampaignId(string $payload, int $campaignId): bool
    {
        if ($campaignId < 1) {
            return false;
        }

        $id = (string) $campaignId;
        if (preg_match('/s:10:\\\\?"campaignId\\\\?";i:'.$id.';/', $payload)) {
            return true;
        }

        if (preg_match('/"campaignId":'.$id.'(?!\d)/', $payload)) {
            return true;
        }

        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? ($decoded['data']['command'] ?? null) : null;

        return is_string($command) && (bool) preg_match('/s:10:"campaignId";i:'.$id.';/', $command);
    }

    public static function dedupeKey(string $payload): ?string
    {
        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? ($decoded['data']['command'] ?? null) : null;
        $haystacks = array_values(array_filter([
            is_string($command) ? $command : null,
            $payload,
        ]));

        foreach ($haystacks as $haystack) {
            if (preg_match('/s:9:\\\\?"dedupeKey\\\\?";s:\d+:\\\\?"([^\\\\"]+)\\\\?"/', $haystack, $matches)) {
                return $matches[1];
            }

            if (preg_match('/s:10:\\\\?"dedupe_key\\\\?";s:\d+:\\\\?"([^\\\\"]+)\\\\?"/', $haystack, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function emails(string $payload): array
    {
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $payload, $matches);

        return array_values(array_unique(array_map('strval', $matches[0] ?? [])));
    }

    public static function queuedAt(string $payload): ?Carbon
    {
        if (! preg_match('/s:8:\\\\?"queuedAt\\\\?";s:\d+:\\\\?"([^\\\\"]+)\\\\?"/', $payload, $matches)) {
            return null;
        }

        try {
            return Carbon::parse($matches[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function refreshQueuedAt(string $payload, ?\DateTimeInterface $at = null): string
    {
        $fresh = Carbon::parse($at ?? now())->toIso8601String();
        $replacement = 's:8:"queuedAt";s:'.strlen($fresh).':"'.$fresh.'"';

        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? ($decoded['data']['command'] ?? null) : null;
        if (is_string($command) && str_contains($command, 'queuedAt')) {
            $decoded['data']['command'] = preg_replace(
                '/s:8:"queuedAt";(?:N|s:\d+:"[^"]*")/',
                $replacement,
                $command,
                1
            ) ?? $command;

            return json_encode($decoded) ?: $payload;
        }

        $updated = preg_replace(
            '/s:8:\\\\?"queuedAt\\\\?";(?:N|s:\d+:\\\\?"[^\\\\"]*\\\\?")/',
            $replacement,
            $payload,
            1
        );

        return is_string($updated) ? $updated : $payload;
    }
}
