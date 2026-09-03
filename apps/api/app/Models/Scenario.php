<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cooperative match's other half: which villain, holding which cards, at what difficulty.
 *
 * The columns are indexes over the document, as everywhere else here. `min_players` and
 * `max_players` narrow the game's own range — a scenario may be written for three or four
 * even where the game seats one.
 */
final class Scenario extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'game_id', 'code', 'name', 'adversary', 'difficulty',
        'min_players', 'max_players', 'document',
    ];

    protected $casts = ['document' => 'array'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return array<string, string> anchor id => card code */
    public function anchors(): array
    {
        return $this->document['anchors'] ?? [];
    }

    /** @return list<string> */
    public function encounterSetCodes(): array
    {
        return $this->document['encounterSets'] ?? [];
    }

    /** The index columns, derived from the document. */
    public static function projected(array $document): array
    {
        return [
            'code' => (string) $document['id'],
            'name' => (string) ($document['name'] ?? $document['id']),
            'adversary' => $document['adversary'] ?? null,
            'difficulty' => $document['difficulty'] ?? null,
            'min_players' => isset($document['playerCount']['min']) ? (int) $document['playerCount']['min'] : null,
            'max_players' => isset($document['playerCount']['max']) ? (int) $document['playerCount']['max'] : null,
            'document' => $document,
        ];
    }
}
