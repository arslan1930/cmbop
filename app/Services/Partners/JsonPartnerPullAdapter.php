<?php

namespace App\Services\Partners;

use App\Models\Partner;
use App\Models\PartnerConnection;
use App\Models\PartnerImportJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class JsonPartnerPullAdapter
{
    public function __construct(private PartnerImportPipeline $pipeline) {}

    /**
     * @param  array{actor?: mixed, skip_reachability?: bool}  $options
     */
    public function sync(Partner $partner, PartnerConnection $connection, array $options = []): PartnerImportJob
    {
        $url = trim((string) ($connection->api_base_url ?: $connection->feed_url));
        if ($url === '') {
            $job = PartnerImportJob::query()->create([
                'partner_id' => $partner->id,
                'connection_id' => $connection->id,
                'source' => 'api_pull',
                'status' => PartnerImportJob::STATUS_FAILED,
                'error' => 'Missing API URL',
                'started_at' => now(),
                'finished_at' => now(),
                'counts' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0, 'blocked' => 0, 'removed' => 0],
            ]);

            return $job;
        }

        $rows = $this->fetchRows($connection, $url);
        $options['connection'] = $connection;
        $options['filename'] = 'pull.json';

        return $this->pipeline->importMappedFieldRows($partner, 'api_pull', $rows, $options);
    }

    /**
     * @return list<array<string, string>>
     */
    public function fetchRows(PartnerConnection $connection, string $url): array
    {
        $pagination = $connection->pagination ?? [];
        $pageParam = (string) ($pagination['page_param'] ?? 'page');
        $start = (int) ($pagination['start_page'] ?? 1);
        $maxPages = min(50, (int) ($pagination['max_pages'] ?? 10));
        $itemsPath = (string) ($connection->items_path ?: 'data');
        $mapping = $connection->field_mapping ?? [];
        $headerName = trim((string) ($connection->auth_header_name ?: 'Authorization'));
        $authKey = $this->plainKey($connection);
        $rows = [];
        for ($page = $start; $page < $start + $maxPages; $page++) {
            $requestUrl = $this->withQuery($url, $pageParam, $page);
            $pending = Http::timeout(20)->acceptJson();
            if ($authKey !== '') {
                $pending = $pending->withHeaders([$headerName => $authKey]);
            }
            $response = $pending->get($requestUrl);
            $response->throw();
            $json = $response->json();
            if (! is_array($json) && is_string($response->body())) {
                $decoded = json_decode($response->body(), true);
                $json = is_array($decoded) ? $decoded : [];
            }
            $items = $this->pluck($json, $itemsPath);
            if (! is_array($items) || $items === []) {
                break;
            }
            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $row = $this->mapItem($item, $mapping);
                $row['_line'] = (string) (($page * 1000) + (int) $index + 1);
                $rows[] = $row;
            }
            if (count($items) < (int) ($pagination['page_size'] ?? 1)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $mapping
     * @return array<string, string>
     */
    public function mapItem(array $item, array $mapping): array
    {
        if ($mapping === []) {
            $mapping = [
                'site_url' => 'url',
                'site_name' => 'name',
                'price' => 'price',
                'currency' => 'currency',
                'country' => 'country',
                'language' => 'language',
                'da' => 'da',
                'dr' => 'dr',
                'monthly_traffic' => 'traffic',
                'contact_email' => 'contact_email',
                'contact_name' => 'contact_name',
                'niches' => 'niches',
                'description' => 'description',
                'turnaround' => 'turnaround',
                'turnaround_days' => 'turnaround_days',
                'publication' => 'publication',
                'publication_length' => 'publication_length',
                'link_type' => 'link_type',
                'sponsored_tag' => 'sponsored_tag',
                'example_sponsored_post' => 'sample_url',
            ];
        }
        $out = [];
        foreach ($mapping as $column => $path) {
            $value = $this->pluck($item, (string) $path);
            if (is_array($value)) {
                $value = implode(';', array_map('strval', $value));
            }
            $out[(string) $column] = $value === null ? '' : (string) $value;
        }
        $mapper = app(PartnerColumnMapper::class);
        foreach ($item as $key => $value) {
            if ($mapper->alias((string) $key) === null) {
                continue;
            }
            if (isset($out[(string) $key]) && $out[(string) $key] !== '') {
                continue;
            }
            if (is_array($value)) {
                $value = implode(';', array_map('strval', $value));
            }
            $out[(string) $key] = $value === null ? '' : (string) $value;
        }

        return $out;
    }

    private function plainKey(PartnerConnection $connection): string
    {
        $raw = (string) ($connection->auth_key_encrypted ?? '');
        if ($raw === '') {
            return '';
        }
        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return $raw;
        }
    }

    private function withQuery(string $url, string $param, int $page): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url.$sep.urlencode($param).'='.$page;
    }

    private function pluck(mixed $data, string $path): mixed
    {
        if ($path === '' || $path === '.') {
            return $data;
        }
        $cursor = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
