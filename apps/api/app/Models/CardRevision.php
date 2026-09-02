<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An earlier state of a card. Append-only, enforced by the database. */
final class CardRevision extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $fillable = ['card_id', 'revision', 'document', 'author_id', 'message'];

    protected $casts = ['document' => 'array', 'created_at' => 'datetime'];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
