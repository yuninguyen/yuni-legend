<?php

namespace App\Filament\Resources\Traits;

trait HasPlatform
{
    public static function getPlatforms(): array
    {
        return \App\Models\Platform::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->toArray();
    }

    /**
     * Check if any active Platform (by name or slug) matches one of the given candidates.
     * Comparison ignores case, spaces, dots and other non-alphanumeric characters.
     */
    public static function isPlatformActive(array $candidates): bool
    {
        $normalize = fn ($value) => strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $value));

        $active = \App\Models\Platform::query()
            ->where('is_active', true)
            ->get(['name', 'slug'])
            ->flatMap(fn ($platform) => [$platform->name, $platform->slug])
            ->map($normalize)
            ->toArray();

        foreach ($candidates as $candidate) {
            if (in_array($normalize($candidate), $active, true)) {
                return true;
            }
        }

        return false;
    }
}
