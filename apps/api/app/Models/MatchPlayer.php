<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One seat at a match: a person or a bot, and the deck they brought. */
final class MatchPlayer extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $fillable = ['match_id', 'seat', 'user_id', 'bot_profile_id', 'deck_version_id', 'label'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function deckVersion(): BelongsTo
    {
        return $this->belongsTo(DeckVersion::class);
    }

    public function isBot(): bool
    {
        return $this->bot_profile_id !== null;
    }
}
