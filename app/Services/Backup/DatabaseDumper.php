<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * mysqldump when the binary exists; otherwise a chunked PHP dump.
 */
class DatabaseDumper
{
    public function dumpTo(string $sqlPath): void
    {
        $dir = dirname($sqlPath);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create backup temp directory.');
        }

        $connection = config('database.default');
        $driver = (string) config('database.connections.'.$connection.'.driver');

        if ($driver === 'mysql' && $this->dumpWithMysqldump($sqlPath)) {
            return;
        }

        $this->dumpWithPhp($sqlPath, $driver);
    }

    public function findMysqldump(): ?string
    {
        foreach (['mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump'] as $bin) {
            $path = $bin === 'mysqldump' ? $this->which('mysqldump') : (is_executable($bin) ? $bin : null);
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        return null;
    }

    private function dumpWithMysqldump(string $sqlPath): bool
    {
        $bin = $this->findMysqldump();
        if ($bin === null) {
            return false;
        }

        $c = config('database.connections.'.config('database.default'));
        $cnf = tempnam(sys_get_temp_dir(), 'mydump');
        if ($cnf === false) {
            return false;
        }

        $host = (string) ($c['host'] ?? 'localhost');
        $port = (int) ($c['port'] ?? 3306);
        $user = (string) ($c['username'] ?? '');
        $pass = (string) ($c['password'] ?? '');
        $db = (string) ($c['database'] ?? '');
        $socket = (string) ($c['unix_socket'] ?? '');

        $cnfBody = "[client]\nuser=".str_replace(["\n", "\r"], '', $user)."\n";
        $cnfBody .= 'password="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $pass)."\"\n";
        if ($socket !== '') {
            $cnfBody .= 'socket='.$socket."\n";
        } else {
            $cnfBody .= 'host='.$host."\nport=".$port."\n";
        }
        file_put_contents($cnf, $cnfBody);
        chmod($cnf, 0600);

        $cmd = sprintf(
            '%s --defaults-extra-file=%s --single-transaction --quick --routines --no-tablespaces %s > %s 2> %s',
            escapeshellarg($bin),
            escapeshellarg($cnf),
            escapeshellarg($db),
            escapeshellarg($sqlPath),
            escapeshellarg($sqlPath.'.err')
        );

        $ok = false;
        try {
            exec($cmd, $out, $code);
            $ok = $code === 0 && is_file($sqlPath) && filesize($sqlPath) > 0;
            if (! $ok) {
                $err = is_file($sqlPath.'.err') ? (string) file_get_contents($sqlPath.'.err') : '';
                Log::warning('mysqldump failed; falling back to PHP dumper', [
                    'code' => $code,
                    'error' => mb_substr($err, 0, 500),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('mysqldump exec failed', ['error' => $e->getMessage()]);
        } finally {
            @unlink($cnf);
            @unlink($sqlPath.'.err');
        }

        return $ok;
    }

    private function dumpWithPhp(string $sqlPath, string $driver): void
    {
        $handle = fopen($sqlPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write database dump.');
        }

        fwrite($handle, "-- Seolinkbuildings PHP dump\nSET FOREIGN_KEY_CHECKS=0;\n");

        try {
            $tables = $this->tableNames($driver);
            foreach ($tables as $table) {
                $this->writeTable($handle, $table, $driver);
            }
        } finally {
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);
        }

        if (! is_file($sqlPath) || filesize($sqlPath) < 1) {
            throw new RuntimeException('PHP database dump was empty.');
        }
    }

    /**
     * @return list<string>
     */
    private function tableNames(string $driver): array
    {
        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

            return array_map(fn ($row) => (string) (is_array($row) ? ($row['name'] ?? '') : $row->name), $rows);
        }

        $rows = DB::select('SHOW TABLES');
        $names = [];
        foreach ($rows as $row) {
            $arr = (array) $row;
            $names[] = (string) reset($arr);
        }

        return array_values(array_filter($names));
    }

    /** @param resource $handle */
    private function writeTable($handle, string $table, string $driver): void
    {
        $quoted = $this->quoteIdent($table, $driver);
        fwrite($handle, "\n-- table {$table}\n");

        if ($driver === 'mysql') {
            try {
                $create = DB::select('SHOW CREATE TABLE '.$quoted);
                $row = isset($create[0]) ? (array) $create[0] : [];
                $stmt = (string) ($row['Create Table'] ?? '');
                if ($stmt !== '') {
                    fwrite($handle, 'DROP TABLE IF EXISTS '.$quoted.";\n".$stmt.";\n");
                }
            } catch (Throwable) {
            }
        }

        $offset = 0;
        $chunk = 200;
        while (true) {
            $rows = DB::table($table)->offset($offset)->limit($chunk)->get();
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                $cols = [];
                $vals = [];
                foreach ((array) $row as $col => $val) {
                    $cols[] = $this->quoteIdent((string) $col, $driver);
                    $vals[] = $this->quoteValue($val);
                }
                fwrite($handle, 'INSERT INTO '.$quoted.' ('.implode(',', $cols).') VALUES ('.implode(',', $vals).");\n");
            }
            $offset += $chunk;
            if ($rows->count() < $chunk) {
                break;
            }
        }
    }

    private function quoteIdent(string $name, string $driver): string
    {
        if ($driver === 'sqlite') {
            return '"'.str_replace('"', '""', $name).'"';
        }

        return '`'.str_replace('`', '``', $name).'`';
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = (string) $value;

        return DB::getPdo()->quote($string);
    }

    private function which(string $bin): ?string
    {
        try {
            $out = [];
            exec('command -v '.escapeshellarg($bin).' 2>/dev/null', $out, $code);
            if ($code === 0 && isset($out[0]) && is_executable($out[0])) {
                return $out[0];
            }
        } catch (Throwable) {
        }

        return null;
    }
}
