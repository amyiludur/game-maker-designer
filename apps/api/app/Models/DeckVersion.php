<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A deck as it stood, pinned to the game version it was legal under. */
final class DeckVersion extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $fillable = ['deck_id', 'version', 'game_version_id', 'document', 'legality'];

    protected $casts = ['document' => 'array', 'legality' => 'array', 'created_at' => 'datetime'];

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    public function gameVersion(): BelongsTo
    {
        return $this->belongsTo(GameVersion::class);
    }

    public function isLegal(): bool
    {
        return ($this->legality['valid'] ?? false) === true;
    }
}
