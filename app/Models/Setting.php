<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    /**
     * Settings resolved for this request. The cache store is the DB on this
     * host, so every Cache::get is a query — and a page reads settings ~90
     * times (theme(), store_name(), every toggle). Holding them for the
     * request turns ~90 queries into one.
     */
    protected static ?array $memo = null;

    public static function get(string $key, $default = null)
    {
        static::$memo ??= Cache::rememberForever('settings.all', fn () => static::pluck('value', 'key')->toArray());

        return static::$memo[$key] ?? $default;
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings.all');
        static::$memo = null;        // a write must be visible to this request too
    }

    /** Drop the in-request copy (tests, queue workers between jobs). */
    public static function flushMemo(): void
    {
        static::$memo = null;
    }
}
