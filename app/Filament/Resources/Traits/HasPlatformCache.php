<?php

namespace App\Filament\Resources\Traits;

trait HasPlatformCache
{
    // 🟢 STATIC CACHE: Loại bỏ truy vấn N+1 cho tên Platform
    protected static array $platformNamesCache = [];

    public static function getPlatformName(?string $slug): string
    {
        if (!$slug) {
            return 'N/A';
        }

        if (empty(static::$platformNamesCache)) {
            static::$platformNamesCache = \App\Models\Platform::pluck('name', 'slug')->toArray();
        }

        return static::$platformNamesCache[$slug] ?? $slug;
    }
}
