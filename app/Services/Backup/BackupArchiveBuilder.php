<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class BackupArchiveBuilder
{
    /**
     * @param  list<string>  $extraRoots
     */
    public function buildZip(string $sqlPath, array $extraRoots = []): string
    {
        $stamp = now()->timezone('Asia/Karachi')->format('Ymd-His');
        $prefix = (string) config('backup.prefix', 'seolinkbuildings-backup');
        $zipPath = storage_path('app/backup-tmp/'.$prefix.'-'.$stamp.'.zip');
        $dir = dirname($zipPath);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create backup temp directory.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create backup zip.');
        }

        $gzPath = $sqlPath.'.gz';
        $this->gzipFile($sqlPath, $gzPath);
        $zip->addFile($gzPath, 'database.sql.gz');

        $roots = array_merge($this->defaultMediaRoots(), $extraRoots);
        foreach ($roots as $root) {
            $this->addDirectory($zip, $root);
        }

        $zip->close();
        @unlink($gzPath);

        if (! is_file($zipPath) || filesize($zipPath) < 1) {
            throw new RuntimeException('Backup zip was empty.');
        }

        return $zipPath;
    }

    /**
     * @return list<string>
     */
    public function defaultMediaRoots(): array
    {
        $configured = config('backup.media_roots');
        if (is_array($configured)) {
            return array_values(array_filter(
                $configured,
                static fn ($root) => is_string($root) && $root !== '' && is_dir($root)
            ));
        }

        $roots = [];
        $publicRoot = (string) config('filesystems.disks.public.root', storage_path('app/public'));
        if ($publicRoot !== '' && is_dir($publicRoot)) {
            $roots[] = $publicRoot;
        }
        $uploads = public_path('uploads');
        if (is_dir($uploads)) {
            $roots[] = $uploads;
        }

        return $roots;
    }

    private function gzipFile(string $src, string $dest): void
    {
        $in = fopen($src, 'rb');
        $out = gzopen($dest, 'wb9');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot gzip database dump.');
        }
        while (! feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            gzwrite($out, $chunk);
        }
        fclose($in);
        gzclose($out);
    }

    private function addDirectory(ZipArchive $zip, string $root): void
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if (! is_dir($root)) {
            return;
        }

        $localBase = basename($root);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            if ($this->shouldSkip($full)) {
                continue;
            }
            $relative = $localBase.'/'.ltrim(str_replace($root, '', $full), DIRECTORY_SEPARATOR);
            $zip->addFile($full, str_replace('\\', '/', $relative));
        }
    }

    private function shouldSkip(string $path): bool
    {
        $normalized = str_replace('\\', '/', strtolower($path));
        foreach (['/cache/', '/logs/', '/vendor/', '/backup-tmp/'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function forgetPath(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }
        if (is_file($path)) {
            File::delete($path);
        }
    }
}
