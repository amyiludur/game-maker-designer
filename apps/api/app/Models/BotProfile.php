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
}
