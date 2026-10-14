<?php

namespace App\Console\Commands;

use App\Support\CountryCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NormalizeCountries extends Command
{
    protected $signature = 'countries:normalize
                            {--preview : Print SELECT-style rows without writing}
                            {--table= : users, sites, or all (default all)}';

    protected $description = 'Normalize users.country and sites.country to ISO-2 (uk not gb). Preview with --preview.';

    public function handle(): int
    {
        $tableOpt = strtolower((string) ($this->option('table') ?: 'all'));
        $tables = $tableOpt === 'all' ? ['users', 'sites'] : [$tableOpt];
        $preview = (bool) $this->option('preview');

        foreach ($tables as $table) {
            if (! in_array($table, ['users', 'sites'], true)) {
                $this->error('Unknown table '.$table);

                return self::FAILURE;
            }
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'country')) {
                $this->warn($table.'.country is unavailable — skipped.');

                continue;
            }

            $this->line($preview ? 'PREVIEW '.$table : 'UPDATE '.$table);
            $rows = DB::table($table)
                ->select('id', 'country')
                ->whereNotNull('country')
                ->where('country', '!=', '')
                ->orderBy('id')
                ->get();

            $changed = 0;
            $unknown = 0;
            foreach ($rows as $row) {
                $raw = (string) $row->country;
                $code = CountryCode::normalize($raw);
                if ($code === null) {
                    $unknown++;
                    $this->line('  #'.$row->id.'  '.json_encode($raw).'  →  (leave / junk)');

                    continue;
                }
                if (strtolower(trim($raw)) === $code) {
                    continue;
                }
                $changed++;
                $this->line('  #'.$row->id.'  '.json_encode($raw).'  →  '.$code);
                if (! $preview) {
                    DB::table($table)->where('id', $row->id)->update(['country' => $code]);
                }
            }

            $this->info($table.': '.$changed.' to rewrite, '.$unknown.' unmapped.'.($preview ? ' (preview)' : ''));
        }

        if ($preview) {
            $this->comment('Re-run without --preview to write. SQL equivalent: database/sql/normalize_country_codes.sql');
        }

        return self::SUCCESS;
    }
}
