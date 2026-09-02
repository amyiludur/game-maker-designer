<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A card.
 *
 * `document` is the card. `name`, `card_type`, `faction`, `cost`, `traits`, `keywords` and
 * `search` are indexes: written only by the projector, never read to make a game decision,
 * and rebuildable by `cards:reproject` (ADR-0001). If every one of them were wrong, matches
 * would still be correct.
 */
final class Card extends Model
{
    use HasUuidV7;

    protected $fillable = ['game_id', 'set_id', 'code', 'document', 'status', 'head_revision_id'];

    protected $casts = [
        'document' => 'array',
        'traits' => 'array',
        'keywords' => 'array',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function set(): BelongsTo
    {
        return $this->belongsTo(CardSet::class, 'set_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CardRevision::class)->orderBy('revision');
    }
}
