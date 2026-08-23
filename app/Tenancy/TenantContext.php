<?php

namespace App\Tenancy;

class TenantContext
{
    protected static ?int $tenantId = null;

    protected static ?object $tenant = null;

    public static function set(object $tenant): void
    {
        static::$tenant = $tenant;
        static::$tenantId = (int) $tenant->id;
    }

    public static function setFromId(?int $tenantId): void
    {
        static::$tenantId = $tenantId;
        static::$tenant = null;
    }

    public static function clear(): void
    {
        static::$tenantId = null;
        static::$tenant = null;
    }

    public static function id(): ?int
    {
        return static::$tenantId;
    }

    public static function tenant(): ?object
    {
        if (static::$tenant === null && static::$tenantId !== null) {
            static::$tenant = \App\Models\Tenant::find(static::$tenantId);
        }

        return static::$tenant;
    }

    public static function enabled(): bool
    {
        return static::$tenantId !== null;
    }
}
