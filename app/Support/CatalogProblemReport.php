<?php

namespace App\Support;

use App\Models\ProblemReport;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog listing reports land in problem_reports as a fixed envelope.
 * Advertiser catalog and admin Community Problems both read that shape here.
 */
class CatalogProblemReport
{
    public static function sanitizePlain(mixed $raw, int $maxChars): string
    {
        $text = is_string($raw) ? $raw : '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/[ \t]{2,}/u", ' ', $text) ?? $text;
        $text = trim($text);
        if (mb_strlen($text) > $maxChars) {
            $text = rtrim(mb_substr($text, 0, $maxChars));
        }

        return $text;
    }

    public static function isCatalog(ProblemReport $report): bool
    {
        $subject = trim((string) $report->subject);
        $message = (string) $report->message;

        return str_starts_with($subject, 'Catalog site:')
            || str_starts_with(ltrim($message), 'Catalog listing');
    }

    public static function siteId(string $message): ?int
    {
        if (preg_match('/^Site ID:\s*(\d+)/m', $message, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    public static function userMessage(string $message): string
    {
        if (preg_match('/(?:^|(?:\r\n|\n))What they wrote(?:\r\n|\n)(.*)\z/s', $message, $match) !== 1) {
            return '';
        }

        return self::sanitizePlain($match[1], 800);
    }

    public static function isAdminSiteEditUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        return preg_match('~/admin/sites/\d+/edit(?:[?#].*)?$~i', $url) === 1;
    }

    public static function publicListingUrl(?Site $site, ?string $liveUrl): ?string
    {
        if ($liveUrl && ! self::isAdminSiteEditUrl($liveUrl)) {
            return $liveUrl;
        }
        if ($site) {
            $fromSite = CommunityInbox::safeHttpUrl($site->site_url ?? null);
            if ($fromSite && ! self::isAdminSiteEditUrl($fromSite)) {
                return $fromSite;
            }
        }

        return null;
    }

    /** Admin/marketing Sites list focused on this row — not the edit form. */
    public static function staffListingUrl(?Site $site, bool $absolute = true): ?string
    {
        if (! $site || (int) $site->id <= 0) {
            return null;
        }

        try {
            $params = [];
            $publisherId = (int) ($site->publisher_id ?? 0);
            if ($publisherId > 0) {
                $params['publisher'] = $publisherId;
            } else {
                $params['all'] = 1;
            }
            $params['site'] = (int) $site->id;

            return staff_route('sites.index', $params, $absolute);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function adminEditUrl(?int $siteId): ?string
    {
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        try {
            return route('admin.sites.edit', $siteId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{site_name: string, subject: string, message: string, page_url: ?string}
     */
    public static function envelope(Site $site, User $user, string $message): array
    {
        $site->loadMissing('publisher:id,name,email');
        $siteName = trim((string) ($site->site_name ?: $site->domain ?: ('Site #'.$site->id)));
        $subject = 'Catalog site: '.$siteName;
        if (strlen($subject) > 160) {
            $subject = substr($subject, 0, 157).'...';
        }

        $lines = [
            'Catalog listing',
            'Site ID: '.$site->id,
            'Name: '.$siteName,
            'URL: '.($site->site_url ?: '—'),
            'Domain: '.($site->domain ?: '—'),
            'DA: '.($site->da ?? '—').'  DR: '.($site->dr ?? '—').'  Traffic: '.($site->traffic ?? '—'),
        ];
        $publisher = $site->publisher;
        if ($publisher) {
            $lines[] = 'Publisher: '.trim($publisher->name.' <'.$publisher->email.'>');
        }
        $lines[] = '';
        $lines[] = 'Reported by';
        $lines[] = trim((string) $user->name).' <'.$user->email.'>';
        $lines[] = 'User ID: '.$user->id;
        $editUrl = self::adminEditUrl((int) $site->id);
        if ($editUrl) {
            $lines[] = 'Admin listing: '.$editUrl;
        }
        $lines[] = '';
        $lines[] = 'What they wrote';
        $lines[] = $message;

        $pageUrl = CommunityInbox::storedPageUrl($site->site_url)
            ?: CommunityInbox::storedPageUrl($editUrl);

        return [
            'site_name' => $siteName,
            'subject' => $subject,
            'message' => implode("\n", $lines),
            'page_url' => $pageUrl,
        ];
    }

    /**
     * @param  Paginator|iterable<int, ProblemReport>  $reports
     * @return array<int, array{user_message: string, site_id: ?int, site: ?Site, edit_url: ?string, live_url: ?string, listing_url: ?string}>
     */
    public static function summariesFor(mixed $reports): array
    {
        if ($reports instanceof Paginator || $reports instanceof LengthAwarePaginator) {
            $items = $reports->items();
        } elseif ($reports instanceof Collection) {
            $items = $reports->all();
        } elseif (is_array($reports)) {
            $items = $reports;
        } elseif ($reports instanceof \Traversable) {
            $items = iterator_to_array($reports);
        } else {
            $items = [];
        }

        $summaries = [];
        $siteIds = [];
        foreach ($items as $report) {
            if (! $report instanceof ProblemReport || ! self::isCatalog($report)) {
                continue;
            }
            $full = (string) $report->message;
            $siteId = self::siteId($full);
            $liveUrl = CommunityInbox::safeHttpUrl($report->page_url);
            if (self::isAdminSiteEditUrl($liveUrl)) {
                $liveUrl = null;
            }
            $summaries[(int) $report->id] = [
                'user_message' => self::userMessage($full),
                'site_id' => $siteId,
                'site' => null,
                'edit_url' => null,
                'live_url' => $liveUrl,
                'listing_url' => null,
            ];
            if ($siteId !== null && $siteId > 0) {
                $siteIds[$siteId] = true;
            }
        }

        try {
            if ($siteIds === [] || ! Schema::hasTable('sites')) {
                return $summaries;
            }
        } catch (\Throwable $e) {
            report($e);

            return $summaries;
        }

        $columns = array_values(array_filter(
            ['id', 'site_name', 'domain', 'site_url', 'publisher_id'],
            fn (string $column) => CommunityInbox::columnExists('sites', $column)
        ));
        if ($columns === [] || ! in_array('id', $columns, true)) {
            return $summaries;
        }

        try {
            $sites = Site::query()
                ->whereIn('id', array_keys($siteIds))
                ->get($columns);
        } catch (\Throwable $e) {
            report($e);

            return $summaries;
        }

        $byId = $sites->keyBy('id');
        foreach ($summaries as $reportId => $row) {
            $site = $row['site_id'] ? $byId->get($row['site_id']) : null;
            if (! $site instanceof Site) {
                continue;
            }
            $summaries[$reportId]['site'] = $site;
            $summaries[$reportId]['edit_url'] = self::adminEditUrl((int) $site->id);
            if ($summaries[$reportId]['live_url'] === null) {
                $fromSite = CommunityInbox::safeHttpUrl($site->site_url ?? null);
                $summaries[$reportId]['live_url'] = self::isAdminSiteEditUrl($fromSite) ? null : $fromSite;
            }
            $summaries[$reportId]['listing_url'] = self::staffListingUrl($site);
        }

        return $summaries;
    }
}
