<?php

namespace App\Services\Advertiser;

use App\Models\ContentSubmission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdvertiserApiArticleService
{
    /**
     * Store buyer-supplied HTML or markdown as a Content Library-shaped article
     * so checkout attach + publisher download reuse the same columns.
     */
    public function createFromText(
        User $user,
        Site $site,
        string $content,
        string $anchor,
        string $targetUrl,
        string $format = 'html',
    ): ContentSubmission {
        $html = $this->toHtml($content, $format);
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $relative = 'content-uploads/'.$user->id.'/api-'.Str::lower(Str::random(16)).'.html';
        Storage::disk('local')->put($relative, $html);

        return ContentSubmission::query()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'copy_index' => 0,
            'original_filename' => 'article.html',
            'title' => Str::limit($anchor !== '' ? $anchor : 'API article', 120, ''),
            'country' => strtolower((string) ($site->country ?? 'us')),
            'language' => strtolower((string) ($site->language ?? 'en')),
            'disk' => 'local',
            'path' => $relative,
            'file_on_disk' => true,
            'mime' => 'text/html',
            'extension' => 'html',
            'size_bytes' => Storage::disk('local')->size($relative) ?: strlen($html),
            'extracted_text' => $text,
            'preview_html' => $html,
            'word_count' => str_word_count($text),
            'evaluation_status' => 'approved',
            'evaluated_at' => now(),
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
            'anchor_text' => $anchor,
            'target_url' => $targetUrl,
            'publication_mode' => ContentSubmission::MODE_IMMEDIATE,
            'timezone' => 'UTC',
            'wizard_step' => 5,
            'expires_at' => now()->addMonths(6),
        ]);
    }

    public function toHtml(string $content, string $format): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }
        if ($format !== 'markdown' && preg_match('/<\\w+[\\s>]/', $content) === 1) {
            return $content;
        }

        $escaped = htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escaped = preg_replace('/\\*\\*(.+?)\\*\\*/s', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\\*(.+?)\\*/s', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace(
            '/\\[([^\\]]+)\\]\\((https?:\\/\\/[^\\s)]+)\\)/',
            '<a href="$2">$1</a>',
            $escaped
        ) ?? $escaped;
        $paragraphs = preg_split('/\\n{2,}/', $escaped) ?: [$escaped];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $html .= '<p>'.nl2br(trim($paragraph), false).'</p>';
        }

        return $html;
    }
}
