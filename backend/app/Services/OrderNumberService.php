<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the human-visible order number for the whole platform.
 *
 * It deliberately is not derived from an order id: imports, soft deletes and
 * separate tenants would otherwise make the displayed sequence unpredictable.
 * A locked settings row gives every entry point (PWA and API) one shared,
 * numeric-only counter.
 */
class OrderNumberService
{
    public const SETTING_KEY = 'order_number_sequence';

    public function next(): string
    {
        return DB::transaction(function (): string {
            $setting = Setting::query()
                ->where('key', self::SETTING_KEY)
                ->lockForUpdate()
                ->first();

            if (! $setting) {
                $setting = Setting::create([
                    'key' => self::SETTING_KEY,
                    'value' => ['next' => 1],
                ]);
                // Lock the newly created row for the remainder of this
                // transaction as well, so later allocations serialize.
                $setting = Setting::query()->whereKey($setting->id)->lockForUpdate()->firstOrFail();
            }

            $value = is_array($setting->value) ? $setting->value : [];
            $number = max(1, (int) ($value['next'] ?? 1));
            $setting->update(['value' => ['next' => $number + 1]]);

            return (string) $number;
        });
    }

    public function reset(): void
    {
        Setting::updateOrCreate(['key' => self::SETTING_KEY], ['value' => ['next' => 1]]);
    }
}
