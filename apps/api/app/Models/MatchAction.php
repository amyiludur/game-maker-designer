<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in the immutable log. Append-only, enforced by the database. */
final class MatchAction extends Model
{
    public $timestamps = false;

    protected $fillable = ['match_id', 'sequence', 'seat', 'action'];

    protected $casts = ['action' => 'array', 'created_at' => 'datetime'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
