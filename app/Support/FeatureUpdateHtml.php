<?php

namespace App\Support;

class FeatureUpdateHtml
{
    public const TEMPLATE_KEY = 'feature_update';

    public const PREFERENCE_KEY = 'system_updates';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function build(array $payload): string
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $intro = trim((string) ($payload['intro'] ?? ''));
        $features = self::normalizeFeatures($payload['features'] ?? []);

        $html = '';
        if ($title !== '') {
            $html .= '<h2>'.e($title).'</h2>';
        }
        if ($intro !== '') {
            $html .= '<p>'.nl2br(e($intro), false).'</p>';
        }

        if ($features !== []) {
            $html .= '<ul>';
            foreach ($features as $feature) {
                $text = e($feature['text']);
                if ($feature['url'] !== null) {
                    $text = '<a href="'.e($feature['url']).'">'.$text.'</a>';
                }
                $image = '';
                if ($feature['image'] !== null) {
                    $image = '<br><img src="'.e($feature['image']).'" alt="">';
                }
                $html .= '<li>'.$text.$image.'</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * @return array{title: string, intro: string, features: list<array{text: string, url: ?string, image: ?string}>}
     */
    public static function fromRequest(array $input): array
    {
        $features = self::normalizeFeatures($input['features'] ?? []);

        return [
            'title' => trim((string) ($input['feature_title'] ?? $input['title'] ?? '')),
            'intro' => trim((string) ($input['feature_intro'] ?? $input['intro'] ?? '')),
            'features' => $features,
        ];
    }

    /**
     * @return list<array{text: string, url: ?string, image: ?string}>
     */
    public static function normalizeFeatures(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (count($out) >= 5) {
                break;
            }
            if (is_string($row)) {
                $row = ['text' => $row];
            }
            if (! is_array($row)) {
                continue;
            }
            $text = trim((string) ($row['text'] ?? $row['title'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = [
                'text' => $text,
                'url' => CampaignHtml::safeHref($row['url'] ?? $row['link'] ?? null, ['http', 'https']),
                'image' => CampaignHtml::safeHref($row['image'] ?? $row['image_url'] ?? null, ['http', 'https']),
            ];
        }

        return $out;
    }
}
