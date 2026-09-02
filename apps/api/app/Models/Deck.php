<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Deck extends Model
{
    use HasUuidV7;

    protected $fillable = ['game_id', 'owner_id', 'name', 'archetype', 'notes', 'head_version_id'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DeckVersion::class)->orderBy('version');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(DeckVersion::class, 'head_version_id');
    }
}
