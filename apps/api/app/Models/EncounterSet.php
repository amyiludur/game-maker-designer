<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bundle of encounter cards a scenario shuffles in.
 *
 * Shared rather than owned by one scenario: a modular set is written once and dropped into
 * several, which is the whole reason the format has them.
 */
final class EncounterSet extends Model
{
    use HasUuidV7;

    protected $fillable = ['game_id', 'code', 'name', 'kind', 'document'];

    protected $casts = ['document' => 'array'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return list<array{code: string, count?: int}> */
    public function cards(): array
    {
        return $this->document['cards'] ?? [];
    }
}
