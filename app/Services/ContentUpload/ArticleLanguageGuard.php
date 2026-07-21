<?php

namespace App\Services\ContentUpload;

/**
 * Lightweight language detection for marketplace article/body vs selected language.
 * Uses stopword scoring — no external API required.
 */
class ArticleLanguageGuard
{
    /**
     * Stopwords / markers per marketplace language code.
     *
     * @var array<string, list<string>>
     */
    private const MARKERS = [
        'en' => ['the', 'and', 'that', 'with', 'for', 'this', 'from', 'have', 'are', 'was', 'were', 'not', 'you', 'your', 'their', 'about', 'which', 'will', 'can', 'into'],
        'de' => ['der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'sich', 'auf', 'für', 'von', 'den', 'dem', 'eine', 'einer', 'werden', 'auch', 'wie', 'noch', 'nach'],
        'fr' => ['les', 'des', 'une', 'est', 'dans', 'pour', 'que', 'qui', 'pas', 'avec', 'sur', 'sont', 'plus', 'par', 'cette', 'aussi', 'mais', 'nous', 'vous', 'être'],
        'nl' => ['de', 'het', 'een', 'van', 'en', 'in', 'is', 'op', 'te', 'dat', 'voor', 'met', 'niet', 'zijn', 'ook', 'aan', 'als', 'er', 'om', 'bij'],
        'sk' => ['je', 'sa', 'na', 'že', 'pre', 'ako', 'ale', 'nie', 'aj', 'sú', 'bola', 'bolo', 'budú', 'ktorý', 'ktorá', 'ktoré', 'ich', 'sme', 'ste', 'pri'],
        'cs' => ['je', 'se', 'na', 'že', 'pro', 'jako', 'ale', 'není', 'jsou', 'který', 'která', 'které', 'bylo', 'byla', 'budou', 'jejich', 'také', 'nebo', 'pouze', 'při'],
        'pl' => ['jest', 'nie', 'się', 'oraz', 'jako', 'który', 'która', 'które', 'było', 'była', 'będą', 'także', 'tylko', 'przez', 'może', 'tego', 'tej', 'tych', 'więc', 'już'],
        'es' => ['los', 'las', 'del', 'una', 'que', 'con', 'para', 'por', 'como', 'más', 'está', 'están', 'pero', 'sus', 'sobre', 'este', 'esta', 'también', 'entre', 'todo'],
        'it' => ['gli', 'che', 'per', 'con', 'una', 'del', 'della', 'sono', 'come', 'più', 'anche', 'non', 'questo', 'questa', 'loro', 'nel', 'nella', 'dei', 'delle', 'essere'],
        'pt' => ['os', 'as', 'uma', 'para', 'com', 'que', 'não', 'como', 'mais', 'está', 'estão', 'pelo', 'pela', 'também', 'sobre', 'este', 'esta', 'seus', 'suas', 'entre'],
        'hu' => ['és', 'nem', 'hogy', 'van', 'egy', 'azt', 'mint', 'volt', 'lesz', 'csak', 'már', 'még', 'ezek', 'azok', 'vagy', 'ami', 'amit', 'szerint', 'után', 'között'],
        'ro' => ['și', 'este', 'pentru', 'care', 'nu', 'din', 'mai', 'sunt', 'cu', 'în', 'sau', 'acest', 'această', 'lor', 'despre', 'după', 'când', 'fost', 'vor', 'fi'],
        'sv' => ['och', 'att', 'det', 'är', 'som', 'för', 'med', 'på', 'av', 'den', 'inte', 'har', 'om', 'ett', 'till', 'kan', 'från', 'var', 'ska', 'eller'],
        'da' => ['og', 'at', 'det', 'er', 'som', 'for', 'med', 'på', 'af', 'den', 'ikke', 'har', 'om', 'et', 'til', 'kan', 'fra', 'var', 'skal', 'eller'],
        'fi' => ['ja', 'on', 'että', 'sei', 'ei', 'ole', 'kun', 'ovat', 'tai', 'jos', 'niin', 'myös', 'mutta', 'kuin', 'tämä', 'tämän', 'heidän', 'voidaan', 'sekä', 'vain'],
        'el' => ['και', 'το', 'την', 'της', 'του', 'που', 'για', 'με', 'από', 'είναι', 'στην', 'στο', 'των', 'ότι', 'δεν', 'ένα', 'μια', 'ως', 'θα', 'να'],
        'bg' => ['и', 'на', 'за', 'се', 'е', 'от', 'с', 'че', 'да', 'не', 'във', 'като', 'са', 'това', 'този', 'тази', 'или', 'при', 'след', 'ще'],
        'hr' => ['je', 'na', 'za', 'se', 'da', 'su', 'i', 'od', 'ne', 'kao', 'koji', 'koja', 'koje', 'biti', 'ili', 'već', 'samo', 'također', 'nakon', 'prije'],
        'sl' => ['je', 'na', 'za', 'se', 'da', 'so', 'in', 'ne', 'kot', 'ki', 'ali', 'tudi', 'po', 'pri', 'bi', 'bo', 'bodo', 'samo', 'že', 'lahko'],
        'lt' => ['ir', 'yra', 'kad', 'su', 'į', 'ne', 'kaip', 'bei', 'ar', 'tai', 'šis', 'ši', 'buvo', 'bus', 'tik', 'taip', 'pat', 'po', 'iki', 'arba'],
        'lv' => ['un', 'ir', 'ka', 'ar', 'uz', 'no', 'nav', 'kā', 'vai', 'tas', 'šis', 'šī', 'bija', 'būs', 'tikai', 'arī', 'pēc', 'par', 'gan', 'bet'],
        'et' => ['ja', 'on', 'et', 'ei', 'ning', 'kui', 'ka', 'või', 'see', 'seda', 'oli', 'olema', 'aga', 'mis', 'kes', 'nende', 'ainult', 'pärast', 'enne', 'väga'],
        'zh' => ['的', '是', '在', '了', '和', '有', '我', '他', '这', '为', '与', '不', '人', '中', '大', '上', '个', '到', '说', '们'],
        'ar' => ['في', 'من', 'على', 'إلى', 'هذا', 'هذه', 'التي', 'الذي', 'أن', 'عن', 'ما', 'مع', 'كان', 'لا', 'أو', 'كل', 'بعد', 'بين', 'حتى', 'عند'],
    ];

    /**
     * @return array{
     *   ok: bool,
     *   detected: ?string,
     *   selected: string,
     *   confidence: float,
     *   scores: array<string, float>,
     *   message: ?string
     * }
     */
    public function assertMatches(string $text, string $selectedLanguage): array
    {
        $selected = strtolower(trim($selectedLanguage));
        $detection = $this->detect($text);

        if (($detection['confidence'] ?? 0) < 0.28 || ! ($detection['language'] ?? null)) {
            return [
                'ok' => true,
                'detected' => $detection['language'] ?? null,
                'selected' => $selected,
                'confidence' => (float) ($detection['confidence'] ?? 0),
                'scores' => $detection['scores'] ?? [],
                'message' => null,
            ];
        }

        $detected = (string) $detection['language'];
        if ($detected === $selected) {
            return [
                'ok' => true,
                'detected' => $detected,
                'selected' => $selected,
                'confidence' => (float) $detection['confidence'],
                'scores' => $detection['scores'],
                'message' => null,
            ];
        }

        $detectedLabel = strtoupper($detected);
        $selectedLabel = strtoupper($selected);

        return [
            'ok' => false,
            'detected' => $detected,
            'selected' => $selected,
            'confidence' => (float) $detection['confidence'],
            'scores' => $detection['scores'],
            'message' => "Article language looks like {$detectedLabel}, but you selected {$selectedLabel}. Write the article in {$selectedLabel}, or change the language selection to match.",
        ];
    }

    /**
     * @return array{language:?string, confidence:float, scores:array<string,float>}
     */
    public function detect(string $text): array
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? '');
        if (mb_strlen($normalized) < 80) {
            return ['language' => null, 'confidence' => 0.0, 'scores' => []];
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 25) {
            return ['language' => null, 'confidence' => 0.0, 'scores' => []];
        }

        $tokenCounts = array_count_values($tokens);
        $scores = [];

        foreach (self::MARKERS as $code => $markers) {
            $hits = 0;
            foreach ($markers as $marker) {
                $hits += (int) ($tokenCounts[$marker] ?? 0);
            }
            $scores[$code] = $hits / max(1, count($tokens));
        }

        arsort($scores);
        $topLang = array_key_first($scores);
        $top = (float) ($scores[$topLang] ?? 0);
        $second = (float) (array_values($scores)[1] ?? 0);
        $confidence = max(0.0, min(1.0, ($top * 8) + (($top - $second) * 4)));

        return [
            'language' => $top > 0.004 ? $topLang : null,
            'confidence' => round($confidence, 3),
            'scores' => array_map(static fn ($v) => round((float) $v, 5), $scores),
        ];
    }
}
