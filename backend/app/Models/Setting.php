<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const BRANDING_KEY = 'platform_branding';

    /**
     * Public, editable content shown in the app's about sheet and legal
     * pages.  Keep it in one JSON setting so translations always travel and
     * are saved together.
     */
    public const PUBLIC_CONTENT_KEY = 'platform_public_content';

    private const PUBLIC_CONTENT_FIELDS = [
        'about_app',
        'developer_name',
        'developer_description',
        'privacy_policy',
        'terms_of_use',
    ];

    private const PUBLIC_CONTENT_LOCALES = ['ar', 'en', 'ku'];

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

    /**
     * Return only the supported public-content fields and locales.  This
     * protects the app from malformed legacy values while allowing the
     * administrator to leave any language blank and retain the UI fallback.
     *
     * @return array<string, array<string, string>>
     */
    public static function publicContent(): array
    {
        return static::normalizePublicContent(static::get(self::PUBLIC_CONTENT_KEY, []));
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function normalizePublicContent(mixed $value): array
    {
        $content = [];

        foreach (self::PUBLIC_CONTENT_FIELDS as $field) {
            $content[$field] = array_fill_keys(self::PUBLIC_CONTENT_LOCALES, '');
        }

        if (! is_array($value)) {
            return $content;
        }

        foreach (self::PUBLIC_CONTENT_FIELDS as $field) {
            $fieldContent = $value[$field] ?? null;
            if (! is_array($fieldContent)) {
                continue;
            }

            foreach (self::PUBLIC_CONTENT_LOCALES as $locale) {
                if (is_string($fieldContent[$locale] ?? null)) {
                    $content[$field][$locale] = trim($fieldContent[$locale]);
                }
            }
        }

        return $content;
    }

    /**
     * The lightweight content needed by the sign-in and profile sheets.  The
     * full legal documents deliberately stay out of globally shared Inertia
     * props, so a long policy never slows ordinary app navigation.
     *
     * @return array<string, array<string, string>>
     */
    public static function developerContent(): array
    {
        $content = static::publicContent();

        return [
            'about_app' => $content['about_app'],
            'developer_name' => $content['developer_name'],
            'developer_description' => $content['developer_description'],
        ];
    }
}
