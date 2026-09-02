<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

/**
 * A decision the game is waiting on.
 *
 * One component per kind on the client, driven entirely by this object, so adding a new
 * kind of choice is a kernel change plus one component and never any per-game work.
 *
 * The choice is only ever sent to the side that must make it; that is part of how hidden
 * information stays hidden (doc 07).
 */
final readonly class PendingChoice
{
    public const CHOOSE_CARDS = 'choose_cards';
    public const CHOOSE_PLAYERS = 'choose_players';
    public const CHOOSE_OPTION = 'choose_option';
    public const CHOOSE_NUMBER = 'choose_number';
    public const YES_NO = 'yes_no';
    public const ORDER_ITEMS = 'order_items';
    public const DISTRIBUTE = 'distribute';

    /**
     * @param  array<string, mixed>  $options  candidates, shaped by kind (cards, players, choices, min/max)
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $side,
        public array $options = [],
        public string $prompt = '',
        public int|array|null $count = 1,
        public bool $optional = false,
        public ?string $sourceInstance = null,
        public ?string $abilityId = null,
    ) {}

    /**
     * The id a replay or a client answers by. Qualified so two abilities on the board
     * asking for a "victim" at the same time cannot be confused for each other.
     */
    public function key(): string
    {
        return implode('.', array_filter([$this->sourceInstance, $this->abilityId, $this->id]));
    }

    public function seat(): int
    {
        return Side::seatOrFail($this->side);
    }
}
