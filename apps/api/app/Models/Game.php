<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Game extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $fillable = ['workspace_id', 'slug', 'name', 'summary', 'current_version_id', 'settings'];

    protected $casts = ['settings' => 'array'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GameVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(GameVersion::class, 'current_version_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function sets(): HasMany
    {
        return $this->hasMany(CardSet::class);
    }

    public function decks(): HasMany
    {
        return $this->hasMany(Deck::class);
    }
}
