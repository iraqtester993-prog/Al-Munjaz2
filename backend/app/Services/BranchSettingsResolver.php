<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Resolves the safe, explicit subset of platform settings that a branch may
 * override.  A missing override always inherits the platform value; a branch
 * can never use this service to write an arbitrary platform setting.
 */
final class BranchSettingsResolver
{
    public const BRANDING_KEY = Setting::BRANDING_KEY;

    public const PUBLIC_CONTENT_KEY = Setting::PUBLIC_CONTENT_KEY;

    /** @var array<int, string> */
    public const OVERRIDABLE_KEYS = [
        self::BRANDING_KEY,
        'support_phone',
        'support_email',
        'currency',
        'delivery_fee',
        'admin_deduction_fee',
        'order_expiry_minutes',
        'pickup_eta_minutes',
        self::PUBLIC_CONTENT_KEY,
    ];

    /** @var array<int, string> */
    public const LOCAL_PUBLIC_CONTENT_FIELDS = [
        'about_app',
        'developer_name',
        'developer_description',
    ];

    /** @var array<int, string> */
    public const CONTENT_LOCALES = ['ar', 'en', 'ku'];

    private const CACHE_TTL_SECONDS = 3600;

    public function get(Branch|int $branch, string $key, mixed $default = null): mixed
    {
        $this->ensureOverridable($key);
        $override = $this->override($branch, $key);

        return $override['exists']
            ? $override['value']
            : Setting::get($key, $default);
    }

    /**
     * Resolve an override only for a branch that is still a live member of
     * the platform delivery network.  Order lifecycle code deliberately uses
     * this method instead of {@see get()}: historical order rows can retain a
     * branch id after that branch has been disabled, deleted, or moved outside
     * the platform tenant.  Such a row must inherit the platform setting, not
     * a stale branch-specific value.
     */
    public function getForOperationalBranch(Branch|int|null $branch, string $key, mixed $default = null): mixed
    {
        $this->ensureOverridable($key);

        $branchId = $this->nullableBranchId($branch);
        if ($branchId === null || ! $this->isLiveOperationalBranch($branchId)) {
            return Setting::get($key, $default);
        }

        return $this->get($branchId, $key, $default);
    }

    public function set(Branch|int $branch, string $key, mixed $value): BranchSetting
    {
        $this->ensureOverridable($key);
        $branchId = $this->branchId($branch);

        $setting = BranchSetting::query()->updateOrCreate(
            ['branch_id' => $branchId, 'key' => $key],
            ['value' => $value],
        );

        Cache::forget($this->cacheKey($branchId, $key));

        return $setting;
    }

    public function forget(Branch|int $branch, string $key): void
    {
        $this->ensureOverridable($key);
        $branchId = $this->branchId($branch);

        BranchSetting::query()
            ->where('branch_id', $branchId)
            ->where('key', $key)
            ->delete();

        Cache::forget($this->cacheKey($branchId, $key));
    }

    public function hasOverride(Branch|int $branch, string $key): bool
    {
        $this->ensureOverridable($key);

        return $this->override($branch, $key)['exists'];
    }

    /** @return array<int, string> */
    public function overriddenKeys(Branch|int $branch): array
    {
        return BranchSetting::query()
            ->where('branch_id', $this->branchId($branch))
            ->whereIn('key', self::OVERRIDABLE_KEYS)
            ->orderBy('key')
            ->pluck('key')
            ->all();
    }

    /** @return array{name:string,tagline:string,logo_path:?string} */
    public function branding(Branch|int $branch): array
    {
        $branding = Setting::branding();
        $override = $this->get($branch, self::BRANDING_KEY, []);

        return is_array($override)
            ? array_replace($branding, array_intersect_key($override, $branding))
            : $branding;
    }

    /** @return array<string, array<string, string>> */
    public function publicContent(Branch|int $branch): array
    {
        $content = Setting::publicContent();
        $override = $this->get($branch, self::PUBLIC_CONTENT_KEY, []);

        if (! is_array($override)) {
            return $content;
        }

        foreach (self::LOCAL_PUBLIC_CONTENT_FIELDS as $field) {
            if (! is_array($override[$field] ?? null)) {
                continue;
            }

            foreach (self::CONTENT_LOCALES as $locale) {
                $value = $override[$field][$locale] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $content[$field][$locale] = trim($value);
                }
            }
        }

        // Privacy policy and terms intentionally remain the one platform-wide
        // legal text.  They are never read from a branch override.
        return $content;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, array<string, string>>
     */
    public function normalizeLocalPublicContent(array $content): array
    {
        $normalized = [];

        foreach (self::LOCAL_PUBLIC_CONTENT_FIELDS as $field) {
            if (! is_array($content[$field] ?? null)) {
                continue;
            }

            foreach (self::CONTENT_LOCALES as $locale) {
                $value = $content[$field][$locale] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $normalized[$field][$locale] = trim($value);
            }

            if (($normalized[$field] ?? []) === []) {
                unset($normalized[$field]);
            }
        }

        return $normalized;
    }

    /** @return array{exists:bool,value:mixed} */
    private function override(Branch|int $branch, string $key): array
    {
        $branchId = $this->branchId($branch);

        /** @var array{exists:bool,value:mixed} $cached */
        $cached = Cache::remember(
            $this->cacheKey($branchId, $key),
            self::CACHE_TTL_SECONDS,
            function () use ($branchId, $key): array {
                $setting = BranchSetting::query()
                    ->where('branch_id', $branchId)
                    ->where('key', $key)
                    ->first(['id', 'value']);

                return [
                    'exists' => $setting !== null,
                    'value' => $setting?->value,
                ];
            },
        );

        return $cached;
    }

    private function ensureOverridable(string $key): void
    {
        if (! in_array($key, self::OVERRIDABLE_KEYS, true)) {
            throw new InvalidArgumentException("The branch setting [{$key}] is not overridable.");
        }
    }

    private function branchId(Branch|int $branch): int
    {
        $id = $branch instanceof Branch ? $branch->getKey() : $branch;

        if (! is_int($id) && ! ctype_digit((string) $id)) {
            throw new InvalidArgumentException('A valid branch id is required.');
        }

        $id = (int) $id;
        if ($id <= 0) {
            throw new InvalidArgumentException('A valid branch id is required.');
        }

        return $id;
    }

    private function nullableBranchId(Branch|int|null $branch): ?int
    {
        if ($branch === null) {
            return null;
        }

        $id = $branch instanceof Branch ? $branch->getKey() : $branch;
        if (! is_int($id) && ! ctype_digit((string) $id)) {
            return null;
        }

        $id = (int) $id;

        return $id > 0 ? $id : null;
    }

    private function isLiveOperationalBranch(int $branchId): bool
    {
        // `withoutGlobalScopes()` is required because an order belongs to a
        // merchant tenant while the delivery-network branch belongs to the
        // platform tenant. Re-add the soft-delete constraint explicitly: it
        // would otherwise be removed together with the tenant scope.
        return Branch::withoutGlobalScopes()
            ->whereKey($branchId)
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function cacheKey(int $branchId, string $key): string
    {
        return 'branch-settings:value:'.hash('sha256', $branchId.'|'.$key);
    }
}
