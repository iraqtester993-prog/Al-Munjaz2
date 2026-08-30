<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_get_uses_the_cached_value_until_set_invalidates_it(): void
    {
        Setting::set('cache-test-setting', ['label' => 'الأصلي']);

        $this->assertSame(['label' => 'الأصلي'], Setting::get('cache-test-setting'));

        // Simulate an out-of-band database change. A regular read should use
        // the cached setting rather than querying and decoding it again.
        DB::table('settings')
            ->where('key', 'cache-test-setting')
            ->update(['value' => json_encode(['label' => 'قديم'])]);

        $this->assertSame(['label' => 'الأصلي'], Setting::get('cache-test-setting'));

        Setting::set('cache-test-setting', ['label' => 'محدّث']);

        $this->assertSame(['label' => 'محدّث'], Setting::get('cache-test-setting'));
    }

    public function test_missing_or_null_settings_keep_each_callers_default(): void
    {
        $this->assertSame('first fallback', Setting::get('missing-cache-test', 'first fallback'));
        $this->assertSame('second fallback', Setting::get('missing-cache-test', 'second fallback'));

        Setting::set('null-cache-test', null);

        $this->assertSame('null fallback', Setting::get('null-cache-test', 'null fallback'));
    }
}
