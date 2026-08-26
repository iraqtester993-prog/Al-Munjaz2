<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const BRANDING_KEY = 'platform_branding';

    protected $fillable = [
        'key', 'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting?->value ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * The dashboard and sign-in page must remain usable before an operator has
     * saved custom branding.  Keep the fallback in one place so every
     * Inertia page gets the same name, subtitle, and logo.
     *
     * @return array{name:string,tagline:string,logo_path:?string}
     */
    public static function branding(): array
    {
        $defaults = [
            'name' => 'المنجز السريع',
            'tagline' => 'لوحة تحكم المنصة',
            'logo_path' => null,
        ];

        $stored = static::get(self::BRANDING_KEY, []);

        return is_array($stored)
            ? array_replace($defaults, array_intersect_key($stored, $defaults))
            : $defaults;
    }
}
