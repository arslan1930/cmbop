<?php

namespace App\Services\Partners;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class PartnerReachability
{
    /**
     * @param  list<string>  $urls
     * @return array<string, array{ok: bool, status: ?int}>
     */
    public function checkMany(array $urls, bool $skip = false): array
    {
        $unique = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url !== '') {
                $unique[$url] = $url;
            }
        }
        if ($skip) {
            $out = [];
            foreach ($unique as $url) {
                $out[$url] = ['ok' => true, 'status' => 200];
            }

            return $out;
        }

        $timeout = max(1.0, (float) config('partners.reachability_timeout', 4));
        $poolSize = max(1, (int) config('partners.reachability_pool', 20));
        $out = [];
        $chunks = array_chunk(array_values($unique), $poolSize);
        foreach ($chunks as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $timeout) {
                foreach ($chunk as $i => $url) {
                    $pool->as((string) $i)->timeout($timeout)->withOptions(['allow_redirects' => true])->get($url);
                }
            });
            foreach ($chunk as $i => $url) {
                $response = $responses[(string) $i] ?? null;
                $ok = false;
                $status = null;
                try {
                    if (is_object($response) && method_exists($response, 'successful')) {
                        $ok = $response->successful();
                        $status = method_exists($response, 'status') ? $response->status() : null;
                    }
                } catch (\Throwable) {
                    $ok = false;
                }
                $out[$url] = ['ok' => $ok, 'status' => $status];
            }
        }

        return $out;
    }
}
