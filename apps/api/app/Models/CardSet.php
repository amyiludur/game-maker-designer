<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A release: the unit a designer plans and budgets in. Named CardSet because Set is taken. */
final class CardSet extends Model
{
    use HasUuidV7;

    protected $table = 'sets';

    protected $fillable = ['game_id', 'code', 'name', 'release_order', 'status', 'document'];

    protected $casts = ['document' => 'array'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class, 'set_id');
    }

    /** Planned counts per card type, which the completeness view compares against reality. */
    public function budget(): array
    {
        return $this->document['design']['budget'] ?? [];
    }
}
