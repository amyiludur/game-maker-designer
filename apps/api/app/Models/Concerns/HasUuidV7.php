<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Time-ordered UUID primary keys.
 *
 * v7 rather than v4 because these are index keys: v7 sorts by creation time, so inserts go
 * to the end of the B-tree instead of scattering across it. And UUIDs rather than
 * auto-increments so that ids can be minted before a write and cannot be enumerated.
 */
trait HasUuidV7
{
    public static function bootHasUuidV7(): void
    {
        static::creating(function ($model): void {
            if ($model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), Str::uuid7()->toString());
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
