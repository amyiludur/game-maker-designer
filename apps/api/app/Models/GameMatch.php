<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A match. Named GameMatch because `match` is a PHP keyword.
 *
 * Pinned to one (game version, deck versions, seed) triple, which is what lets a replay
 * from months ago still reproduce and what makes an A/B balance run mean something.
 */
final class GameMatch extends Model
{
    use HasUuidV7;

    protected $table = 'matches';

    public const LOBBY = 'lobby';
    public const ACTIVE = 'active';
    public const COMPLETE = 'complete';
    public const ABANDONED = 'abandoned';

    protected $fillable = [
        'game_id', 'game_version_id', 'mode', 'status', 'seed',
        'config', 'initial_state', 'result', 'action_count', 'created_by',
    ];

    protected $casts = [
        'config' => 'array',
        'initial_state' => 'array',
        'result' => 'array',
        'seed' => 'integer',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function gameVersion(): BelongsTo
    {
        return $this->belongsTo(GameVersion::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(MatchPlayer::class, 'match_id')->orderBy('seat');
    }

    /** The action log. This, plus the initial state, is the match. */
    public function actions(): HasMany
    {
        return $this->hasMany(MatchAction::class, 'match_id')->orderBy('sequence');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(MatchSnapshot::class, 'match_id')->orderBy('sequence');
    }
}
