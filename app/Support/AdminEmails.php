<?php

namespace App\Support;

use App\Models\EmailLog;
use Illuminate\Http\Request;

/**
 * Admin Email Center recent-log filters and return URL.
 */
class AdminEmails
{
    public const SESSION_KEY = 'admin_emails_return';

    /**
     * @return array<string, string|int>
     */
    public static function indexQuery(Request $request): array
    {
        $status = search_text($request->input('status'));
        if (! in_array($status, ['pending', 'delivered', 'failed'], true)) {
            $status = '';
        }

        $template = search_text($request->input('template_key'));
        if (strlen($template) > 80) {
            $template = substr($template, 0, 80);
        }
        $known = array_keys(EmailCatalog::templates());
        if ($template !== '' && ! in_array($template, $known, true)) {
            $template = '';
        }

        $email = search_text($request->input('to_email'));
        if (strlen($email) > 190) {
            $email = substr($email, 0, 190);
        }

        $source = search_text($request->input('source'));
        if (! in_array($source, ['live', 'test'], true)) {
            $source = '';
        }

        $query = array_filter([
            'status' => $status !== '' ? $status : null,
            'template_key' => $template !== '' ? $template : null,
            'to_email' => $email !== '' ? $email : null,
            'date_from' => self::validDate($request->input('date_from')),
            'date_to' => self::validDate($request->input('date_to')),
            'source' => $source !== '' ? $source : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $page = (int) (filter_number($request->input('page')) ?? 0);
        if ($page > 1) {
            $query['page'] = min($page, 10000);
        }

        return $query;
    }

    /**
     * @return array{status: ?string, template_key: ?string, to_email: ?string, date_from: ?string, date_to: ?string, source: ?string}
     */
    public static function recentFilters(Request $request): array
    {
        $query = self::indexQuery($request);

        return [
            'status' => isset($query['status']) ? (string) $query['status'] : null,
            'template_key' => isset($query['template_key']) ? (string) $query['template_key'] : null,
            'to_email' => isset($query['to_email']) ? (string) $query['to_email'] : null,
            'date_from' => isset($query['date_from']) ? (string) $query['date_from'] : null,
            'date_to' => isset($query['date_to']) ? (string) $query['date_to'] : null,
            'source' => isset($query['source']) ? (string) $query['source'] : null,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    public static function rememberReturnQuery(Request $request): array
    {
        $query = self::indexQuery($request);
        try {
            $request->session()->put(self::SESSION_KEY, $query);
        } catch (\Throwable) {
        }

        return $query;
    }

    /**
     * @return array<string, string|int>
     */
    public static function storedReturnQuery(Request $request): array
    {
        $fromRequest = self::indexQuery($request);
        if ($fromRequest !== []) {
            try {
                $request->session()->put(self::SESSION_KEY, $fromRequest);
            } catch (\Throwable) {
            }

            return $fromRequest;
        }

        try {
            $stored = $request->session()->get(self::SESSION_KEY);
        } catch (\Throwable) {
            return [];
        }
        if (! is_array($stored)) {
            return [];
        }

        return self::indexQuery(Request::create('/', 'GET', $stored));
    }

    public static function listUrl(mixed $query = []): string
    {
        $query = is_array($query) ? self::indexQuery(Request::create('/', 'GET', $query)) : [];

        return route('admin.emails.index', $query).'#ec-recent';
    }

    /**
     * @return array<string, string>
     */
    public static function kpiQuery(string $tile): array
    {
        $today = now()->toDateString();

        return match ($tile) {
            'sent_today' => ['date_from' => $today, 'date_to' => $today],
            'delivered' => ['status' => 'delivered', 'date_from' => $today, 'date_to' => $today],
            'pending' => ['status' => 'pending'],
            'failed' => ['status' => 'failed'],
            default => [],
        };
    }

    public static function isTestLog(?EmailLog $log): bool
    {
        if (! $log) {
            return false;
        }

        if (data_get($log->meta, 'source') === 'email_center_test') {
            return true;
        }

        return str_starts_with((string) $log->dedupe_key, 'email_center_test:');
    }

    public static function templateName(?string $key): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return '—';
        }

        return (string) (EmailCatalog::get($key)['name'] ?? $key);
    }

    public static function previewAudience(?string $value): ?string
    {
        $value = search_text($value);

        return in_array($value, ['advertiser', 'publisher', 'admin', 'completed'], true)
            ? $value
            : null;
    }

    public static function validDate(mixed $value): ?string
    {
        $value = search_text($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof \DateTimeImmutable) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }
}
