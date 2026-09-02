<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a game's rules.
 *
 * `document` is the truth. `compiled` is derived and disposable — per-card-type schemas,
 * form descriptors, the rules digest — and is what makes the card editor build its own
 * forms without a line of per-game frontend code.
 */
final class GameVersion extends Model
{
    use HasUuidV7;

    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';

    protected $fillable = ['game_id', 'semver', 'status', 'document', 'compiled', 'lint', 'parent_version_id', 'published_at'];

    protected $casts = [
        'document' => 'array',
        'compiled' => 'array',
        'lint' => 'array',
        'published_at' => 'datetime',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** Published versions are frozen — by a database trigger, not by hoping. */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }
}
