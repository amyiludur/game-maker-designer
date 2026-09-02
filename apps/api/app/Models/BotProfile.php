<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bot's tuning.
 *
 * Strategy is code; weights and priorities are data, so a designer can retune a bot without
 * a deploy — and so a simulation batch can say which tuning produced its numbers.
 */
final class BotProfile extends Model
{
    use HasUuidV7;

    protected $fillable = ['game_id', 'name', 'strategy', 'config'];

    protected $casts = ['config' => 'array'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * The built-in random opponent.
     *
     * Game-agnostic on purpose: uniform choice among the legal actions needs to know nothing
     * about a game, which is why doc 09 lists it as the strategy to "run first, always". It
     * has no `game_id`, so every game gets an opponent to play against without anyone
     * authoring one.
     */
    public static function ensureRandom(): self
    {
        return self::query()->firstOrCreate(
            ['game_id' => null, 'strategy' => 'random'],
            ['name' => 'Random'],
        );
    }
}
