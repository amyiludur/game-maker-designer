<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use Gmd\Kernel\System\AttributeDefinition;
use Gmd\Kernel\System\CardTypeDefinition;

/**
 * The document a card starts life as.
 *
 * A new card is not an empty object: it is the smallest document that passes both schemas
 * that govern it, which means every required attribute of its card type already carries a
 * value. That is what makes "New card" open an editor rather than a validation error — and
 * the values come from the card type's own declarations, so a game whose characters have
 * `resolve` and `terror` gets those, with no idea here of what a card usually has.
 */
final class CardDraft
{
    public function __construct(private readonly GameCompiler $compiler) {}

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException if the game has no such card type
     */
    public function blank(Game $game, string $type, ?CardSet $set, string $name, ?string $faction = null): array
    {
        $definition = $this->cardType($game, $type);
        $code = $this->nextCode($game, $set);

        $document = [
            'schemaVersion' => '1.0.0',
            'code' => $code,
            'gameId' => $game->slug,
            'name' => $name,
            'type' => $type,
            'quantity' => 1,
            'attributes' => $this->attributes($definition),
            'abilities' => [],
            'text' => null,
            'design' => ['status' => 'draft'],
        ];

        if ($set !== null) {
            $document['setId'] = $set->code;
            $document['number'] = (int) explode('-', $code)[1];
        }
        if ($faction !== null && $faction !== '') {
            $document['faction'] = $faction;
        }

        return $document;
    }

    /**
     * The same card under a new code, ready to be edited apart from its original.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function copyOf(array $document, Game $game, ?CardSet $set, ?string $name = null): array
    {
        $code = $this->nextCode($game, $set);

        $document['code'] = $code;
        $document['name'] = $name ?? trim((string) ($document['name'] ?? 'Card')) . ' (copy)';
        // A copy is a fresh design decision, whatever the original's status had reached.
        $document['design'] = [...($document['design'] ?? []), 'status' => 'draft'];

        if ($set !== null) {
            $document['setId'] = $set->code;
            $document['number'] = (int) explode('-', $code)[1];
        }

        return $document;
    }

    /**
     * The next free code in a set: `core-019` after `core-018`.
     *
     * Numbered per set rather than per game, because a card's code is how a designer refers
     * to it out loud and in a spreadsheet, and set-relative numbering is what they read on
     * the printed card.
     */
    public function nextCode(Game $game, ?CardSet $set): string
    {
        $prefix = $set?->code ?? $game->slug;
        $prefix = preg_replace('/[^a-z0-9]/', '', strtolower($prefix)) ?: 'card';

        $highest = 0;
        foreach ($game->cards()->pluck('code') as $code) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-([0-9]+)$/', (string) $code, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return sprintf('%s-%03d', $prefix, $highest + 1);
    }

    /** Whether a code is already taken in this game — the unique index, asked politely. */
    public function codeExists(Game $game, string $code): bool
    {
        return Card::query()->where('game_id', $game->id)->where('code', $code)->exists();
    }

    /**
     * A value for every attribute the type requires, and for every one that declares a default.
     *
     * @return array<string, mixed>
     */
    private function attributes(CardTypeDefinition $type): array
    {
        $attributes = [];

        foreach ($type->attributes as $attribute) {
            if ($attribute->default !== null) {
                $attributes[$attribute->id] = $attribute->default;

                continue;
            }
            if (! $attribute->required) {
                continue;
            }

            $attributes[$attribute->id] = $this->zeroValue($attribute);
        }

        return $attributes;
    }

    /** The emptiest value of this attribute's type that its own declaration still allows. */
    private function zeroValue(AttributeDefinition $attribute): mixed
    {
        return match ($attribute->type) {
            // `min`, not 0: a health of 1 is what "the smallest legal character" means in a
            // game that says health starts at 1, and a card the editor cannot save is worse
            // than one whose numbers need changing.
            'integer' => (int) ($attribute->min ?? 0),
            'decimal' => (float) ($attribute->min ?? 0),
            'boolean' => false,
            'enum' => $attribute->options[0] ?? '',
            'tagList' => [],
            default => '',
        };
    }

    private function cardType(Game $game, string $type): CardTypeDefinition
    {
        $version = $game->currentVersion;
        if ($version === null) {
            throw new \InvalidArgumentException('this game has no version to author against');
        }

        $system = $this->compiler->compile($version);
        foreach ($system->cardTypes as $definition) {
            if ($definition->id === $type) {
                return $definition;
            }
        }

        throw new \InvalidArgumentException("\"{$type}\" is not a card type in this game");
    }
}
