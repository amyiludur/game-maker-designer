<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved position, purely so the scrubber does not replay from the beginning.
 *
 * The invariant a scheduled verifier asserts: replaying the log from the initial state must
 * reproduce every snapshot exactly. A mismatch means non-determinism has crept into the
 * kernel, which is a P0 (ADR-0005).
 */
final class MatchSnapshot extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $fillable = ['match_id', 'sequence', 'state', 'state_hash'];

    protected $casts = ['state' => 'array', 'created_at' => 'datetime'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
