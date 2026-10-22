<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WritingSetting extends Model
{
    public const KEY_TURNAROUND_TEXT = 'turnaround_text';

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function ensureTable(): void
    {
        try {
            if (Schema::hasTable((new static)->getTable())) {
                return;
            }

            Schema::create((new static)->getTable(), function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        } catch (\Throwable $e) {
            Log::warning('Could not create writing_settings at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function defaultTurnaroundText(): string
    {
        $text = trim((string) config('orders.writing.turnaround_text', '2–3 working days'));

        return $text !== '' ? $text : '2–3 working days';
    }

    public static function turnaroundText(): string
    {
        self::ensureTable();
        $stored = self::getValue(self::KEY_TURNAROUND_TEXT);
        $text = '';
        if (is_array($stored)) {
            $text = trim((string) ($stored['text'] ?? ''));
        } elseif (is_string($stored)) {
            $text = trim($stored);
        }

        return $text !== '' ? $text : self::defaultTurnaroundText();
    }

    public static function setTurnaroundText(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            $text = self::defaultTurnaroundText();
        }

        self::setValue(self::KEY_TURNAROUND_TEXT, ['text' => $text]);
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return $default;
        }

        try {
            $row = static::query()->where('key', $key)->orderBy('id')->first();

            return $row?->value ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function setValue(string $key, mixed $value): void
    {
        self::ensureTable();
        if (! Schema::hasTable((new static)->getTable())) {
            return;
        }

        $row = static::query()->where('key', $key)->orderBy('id')->first();
        if ($row === null) {
            static::query()->create(['key' => $key, 'value' => $value]);
        } else {
            $row->value = $value;
            $row->save();
            static::query()->where('key', $key)->where('id', '!=', $row->id)->delete();
        }
        Cache::forget('writing_setting:'.$key);
    }
}
