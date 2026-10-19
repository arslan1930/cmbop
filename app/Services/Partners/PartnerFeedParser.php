<?php

namespace App\Services\Partners;

/**
 * Parse CSV / XLSX / JSON / XML partner catalogs into associative field rows.
 */
class PartnerFeedParser
{
    public function __construct(private PartnerColumnMapper $mapper) {}

    /**
     * @return list<array<string, string>>
     */
    public function parse(string $contents, string $filename = 'feed.csv'): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $trimmed = ltrim($contents);
        if ($ext === 'xlsx' || str_starts_with($contents, 'PK')) {
            return $this->parseXlsx($contents);
        }
        if ($ext === 'json' || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            return $this->parseJson($trimmed);
        }
        if ($ext === 'xml' || str_starts_with($trimmed, '<')) {
            return $this->parseXml($trimmed);
        }

        return $this->parseCsv($contents);
    }

    /**
     * @return list<array<string, string>>
     */
    public function parseCsv(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\R/', $contents) ?: [];
        $rows = [];
        $headers = null;
        $lineNo = 0;
        foreach ($lines as $line) {
            $lineNo++;
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $delimiter = $this->detectDelimiter($trimmed);
            $cols = str_getcsv($line, $delimiter, '"', '');
            if ($headers === null) {
                $mapped = $this->mapper->normalizeHeaders($cols);
                $known = array_filter($mapped);
                if (count($known) >= 2) {
                    $headers = $mapped;

                    continue;
                }
                $headers = [];
            }
            $rows[] = $headers !== []
                ? $this->mapper->rowFromMapped($cols, $headers, $lineNo)
                : $this->fallbackPositional($cols, $lineNo);
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseJson(string $contents): array
    {
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            return [];
        }
        $list = $decoded;
        foreach (['sites', 'data', 'items', 'rows'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $list = $decoded[$key];
                break;
            }
        }
        $rows = [];
        $i = 0;
        foreach ($list as $record) {
            $i++;
            if (! is_array($record)) {
                continue;
            }
            $rows[] = $this->mapper->rowFromAssoc($record, $i);
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseXml(string $contents): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            return [];
        }
        $records = [];
        $this->collectXmlRecords($xml, $records);
        $rows = [];
        foreach ($records as $i => $record) {
            $rows[] = $this->mapper->rowFromAssoc($record, $i + 1);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $records
     */
    private function collectXmlRecords(\SimpleXMLElement $node, array &$records): void
    {
        $children = $node->children();
        if (count($children) === 0) {
            return;
        }
        $assoc = [];
        $nested = false;
        foreach ($children as $child) {
            if ($child->children()->count() > 0) {
                $nested = true;
                $this->collectXmlRecords($child, $records);
            } else {
                $assoc[$child->getName()] = trim((string) $child);
            }
        }
        if (! $nested && $assoc !== []) {
            $records[] = $assoc;
        }
    }

    /**
     * Minimal first-sheet XLSX reader (shared strings + inline strings).
     *
     * @return list<array<string, string>>
     */
    private function parseXlsx(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'slb-xlsx-');
        if ($tmp === false) {
            return [];
        }
        file_put_contents($tmp, $binary);
        $zip = new \ZipArchive;
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);

            return [];
        }
        $shared = $this->xlsxSharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml') ?: $zip->getFromName('xl/worksheets/sheet.xml');
        $zip->close();
        @unlink($tmp);
        if (! is_string($sheet) || $sheet === '') {
            return [];
        }

        $grid = $this->xlsxSheetRows($sheet, $shared);
        if ($grid === []) {
            return [];
        }
        $headerRow = array_shift($grid);
        $headers = $this->mapper->normalizeHeaders($headerRow);
        $rows = [];
        $line = 1;
        foreach ($grid as $cols) {
            $line++;
            if (implode('', array_map('strval', $cols)) === '') {
                continue;
            }
            $rows[] = $this->mapper->rowFromMapped($cols, $headers, $line);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function xlsxSharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $out = [];
        if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $xml, $m) < 1) {
            return [];
        }
        foreach ($m[1] as $block) {
            $text = '';
            if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $block, $tm)) {
                foreach ($tm[1] as $t) {
                    $text .= html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
            $out[] = $text;
        }

        return $out;
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private function xlsxSheetRows(string $xml, array $shared): array
    {
        $rows = [];
        if (preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $xml, $rowMatch) < 1) {
            return [];
        }
        foreach ($rowMatch[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $rowXml, $cellMatch, PREG_SET_ORDER)) {
                foreach ($cellMatch as $cell) {
                    $attrs = $cell[1];
                    $inner = $cell[2];
                    $col = 0;
                    if (preg_match('/r="([A-Z]+)\d+"/i', $attrs, $ref)) {
                        $col = $this->xlsxColumnIndex($ref[1]);
                    }
                    $isShared = preg_match('/t="s"/', $attrs) === 1;
                    $value = '';
                    if (preg_match('/<v[^>]*>(.*?)<\/v>/s', $inner, $vm)) {
                        $value = html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                        if ($isShared && is_numeric($value) && isset($shared[(int) $value])) {
                            $value = $shared[(int) $value];
                        }
                    } elseif (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm)) {
                        $value = html_entity_decode($tm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                    $cells[$col] = $value;
                }
            }
            if ($cells === []) {
                continue;
            }
            ksort($cells);
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = (string) ($cells[$i] ?? '');
            }
            $rows[] = $line;
        }

        return $rows;
    }

    private function xlsxColumnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $n = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }

        return max(0, $n - 1);
    }

    /**
     * @param  list<mixed>  $cols
     * @return array<string, string>
     */
    private function fallbackPositional(array $cols, int $line): array
    {
        $keys = ['email', 'name', 'url', 'price', 'currency', 'country', 'language', 'da', 'dr', 'traffic', 'site_name', 'example_url', 'turnaround', 'publication', 'link_type', 'tag', 'niches', 'description'];
        $fields = ['_line' => (string) $line];
        foreach ($keys as $i => $key) {
            $fields[$key] = trim((string) ($cols[$i] ?? ''));
        }

        return $fields;
    }

    private function detectDelimiter(string $line): string
    {
        $comma = substr_count($line, ',');
        $semi = substr_count($line, ';');
        $tab = substr_count($line, "\t");
        if ($tab > $comma && $tab > $semi) {
            return "\t";
        }

        return $semi > $comma ? ';' : ',';
    }
}
