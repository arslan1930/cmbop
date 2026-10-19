<?php

namespace App\Services\Partners;

class PartnerContactMasker
{
    public const FLAGGED = ['commission', 'markup', 'margin', 'direct', 'outside the platform', 'casino'];

    /**
     * @return array{masked: string, original: string, rules: list<string>, held: bool}
     */
    public function mask(string $text, array $allowedUrls = [], array $knownNames = []): array
    {
        $original = $text;
        $rules = [];
        $masked = $text;

        $masked = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email removed]', $masked, -1, $n) ?? $masked;
        if ($n > 0) {
            $rules[] = 'email';
        }

        $masked = preg_replace('/(?:\+|00)[\d\s().\-]{8,}\d/', '[phone removed]', $masked, -1, $n) ?? $masked;
        if ($n > 0) {
            $rules[] = 'phone';
        }

        $masked = preg_replace(
            '#(?:https?:)?//(?:(?:www\.)?(?:t\.me|telegram\.me|wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com|discord\.gg|linkedin\.com/in)|m\.me)/[^\s<>"\']+#i',
            '[contact removed]',
            $masked,
            -1,
            $n
        ) ?? $masked;
        if ($n > 0) {
            $rules[] = 'messenger';
        }

        $masked = preg_replace_callback(
            '#https?://[^\s<>"\']+#i',
            function (array $m) use ($allowedUrls, &$rules) {
                $url = $m[0];
                foreach ($allowedUrls as $allowed) {
                    if ($allowed !== '' && str_contains(strtolower($url), strtolower((string) $allowed))) {
                        return $url;
                    }
                }
                if (str_contains(strtolower($url), 'seolinkbuildings.com')) {
                    return $url;
                }
                $rules[] = 'url';

                return '[link removed]';
            },
            $masked
        ) ?? $masked;

        $masked = preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', '[number removed]', $masked, -1, $n) ?? $masked;
        if ($n > 0) {
            $rules[] = 'iban';
        }
        $masked = preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[number removed]', $masked, -1, $n) ?? $masked;
        if ($n > 0) {
            $rules[] = 'card';
        }

        foreach ($knownNames as $name) {
            $name = trim((string) $name);
            if (mb_strlen($name) < 4) {
                continue;
            }
            $replaced = str_ireplace($name, '[name removed]', $masked);
            if ($replaced !== $masked) {
                $rules[] = 'name';
                $masked = $replaced;
            }
        }

        $held = $rules !== [] || $this->containsFlagged($original);

        return [
            'masked' => $masked,
            'original' => $original,
            'rules' => array_values(array_unique($rules)),
            'held' => $held,
        ];
    }

    public function maskFilename(string $filename): string
    {
        $result = $this->mask($filename);

        return $result['masked'];
    }

    public function containsFlagged(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach (self::FLAGGED as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return false;
    }
}
