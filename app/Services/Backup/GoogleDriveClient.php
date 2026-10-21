<?php

namespace App\Services\Backup;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleDriveClient
{
    public function configured(): bool
    {
        foreach (['client_id', 'client_secret', 'refresh_token', 'folder_id'] as $key) {
            if (trim((string) config('backup.google.'.$key)) === '') {
                return false;
            }
        }

        return true;
    }

    public function accessToken(): string
    {
        $response = Http::asForm()->timeout(20)->connectTimeout(10)->post((string) config('backup.google.token_url'), [
            'client_id' => config('backup.google.client_id'),
            'client_secret' => config('backup.google.client_secret'),
            'refresh_token' => config('backup.google.refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('Google Drive token refresh failed: '.$response->body());
        }

        return (string) $response->json('access_token');
    }

    public function testCredentials(): array
    {
        $token = $this->accessToken();
        $folderId = (string) config('backup.google.folder_id');
        $response = $this->http($token)->get((string) config('backup.google.files_url').'/'.$folderId, [
            'fields' => 'id,name,mimeType',
            'supportsAllDrives' => 'true',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google Drive folder check failed: '.$response->body());
        }

        return [
            'folder_id' => (string) $response->json('id'),
            'folder_name' => (string) $response->json('name'),
        ];
    }

    public function uploadFile(string $path, string $name, string $mime = 'application/zip'): string
    {
        $token = $this->accessToken();
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Cannot stat backup zip.');
        }

        $session = $this->http($token)
            ->withHeaders([
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type' => $mime,
                'X-Upload-Content-Length' => (string) $size,
            ])
            ->post((string) config('backup.google.upload_url').'?uploadType=resumable&supportsAllDrives=true', [
                'name' => $name,
                'parents' => [(string) config('backup.google.folder_id')],
            ]);

        $location = $session->header('Location');
        if (! $session->successful() || ! is_string($location) || $location === '') {
            throw new RuntimeException('Google Drive resumable session failed: '.$session->body());
        }

        $chunk = (int) config('backup.chunk_bytes', 8 * 256 * 1024);
        $chunk = max(256 * 1024, $chunk - ($chunk % (256 * 1024)));
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read backup zip for upload.');
        }

        $offset = 0;
        $fileId = '';
        try {
            while ($offset < $size) {
                $length = (int) min($chunk, $size - $offset);
                $data = fread($handle, $length);
                if ($data === false || $data === '') {
                    throw new RuntimeException('Backup zip read failed mid-upload.');
                }
                $end = $offset + strlen($data) - 1;
                $put = Http::withToken($token)
                    ->timeout(60)
                    ->connectTimeout(15)
                    ->withHeaders([
                        'Content-Length' => (string) strlen($data),
                        'Content-Range' => "bytes {$offset}-{$end}/{$size}",
                        'Content-Type' => $mime,
                    ])
                    ->withBody($data, $mime)
                    ->put($location);

                if ($put->status() === 308) {
                    $offset = $end + 1;

                    continue;
                }

                if (! $put->successful()) {
                    throw new RuntimeException('Google Drive upload chunk failed: '.$put->body());
                }

                $fileId = (string) $put->json('id');
                $offset = $end + 1;
            }
        } finally {
            fclose($handle);
        }

        if ($fileId === '') {
            throw new RuntimeException('Google Drive upload did not return a file id.');
        }

        return $fileId;
    }

    /**
     * @return list<array{id: string, name: string, createdTime: string}>
     */
    public function listOwnPrefixedFiles(): array
    {
        $token = $this->accessToken();
        $prefix = (string) config('backup.prefix', 'seolinkbuildings-backup');
        $folderId = (string) config('backup.google.folder_id');
        $q = sprintf(
            "'%s' in parents and name contains '%s' and trashed = false",
            str_replace("'", "\\'", $folderId),
            str_replace("'", "\\'", $prefix)
        );

        $files = [];
        $pageToken = null;
        do {
            $params = [
                'q' => $q,
                'orderBy' => 'createdTime',
                'pageSize' => 100,
                'fields' => 'nextPageToken,files(id,name,createdTime)',
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if (is_string($pageToken) && $pageToken !== '') {
                $params['pageToken'] = $pageToken;
            }
            $response = $this->http($token)->get((string) config('backup.google.files_url'), $params);
            if (! $response->successful()) {
                throw new RuntimeException('Google Drive list failed: '.$response->body());
            }
            foreach ($response->json('files') ?? [] as $file) {
                $name = (string) ($file['name'] ?? '');
                if (! str_starts_with($name, $prefix)) {
                    continue;
                }
                $files[] = [
                    'id' => (string) ($file['id'] ?? ''),
                    'name' => $name,
                    'createdTime' => (string) ($file['createdTime'] ?? ''),
                ];
            }
            $pageToken = $response->json('nextPageToken');
        } while (is_string($pageToken) && $pageToken !== '');

        $files = array_values(array_filter($files, fn ($f) => $f['id'] !== ''));
        usort($files, function (array $a, array $b): int {
            $byTime = strcmp($a['createdTime'], $b['createdTime']);

            return $byTime !== 0 ? $byTime : strcmp($a['id'], $b['id']);
        });

        return $files;
    }

    public function pruneOwnPrefixedFiles(int $keep): int
    {
        $keep = max(1, $keep);
        $files = $this->listOwnPrefixedFiles();
        if (count($files) <= $keep) {
            return 0;
        }

        $token = $this->accessToken();
        $overflow = array_slice($files, 0, count($files) - $keep);
        $deleted = 0;
        foreach ($overflow as $file) {
            $response = $this->http($token)->delete(
                (string) config('backup.google.files_url').'/'.$file['id'],
                ['supportsAllDrives' => 'true']
            );
            if ($response->successful() || $response->status() === 204) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(60);
    }
}
