<?php

declare(strict_types=1);

namespace Gmd\Kernel\Event;

/**
 * The core event catalogue, with the payload each one carries.
 *
 * Written down because trigger filters read these fields by name: a card that says "when a
 * card enters play" is comparing `$event.to` to `"play"`, and nothing else in the system
 * tells an author that `to` is the field or that it holds an unqualified zone id. The
 * linter checks trigger filters against this table.
 */
final class EventCatalogue
{
    /** @var array<string, list<string>> event type => payload keys */
    public const EVENTS = [
        'match.began' => [],
        'round.began' => ['round'],
        'round.ended' => ['round'],
        'phase.began' => ['phase'],
        'phase.ended' => ['phase'],
        'step.began' => ['phase', 'step'],
        'step.ended' => ['phase', 'step'],
        'turn.began' => ['player'],
        'turn.ended' => ['player'],
        'card.played' => ['card', 'player', 'action'],
        // `to` and `from` are unqualified zone ids ("play"), not zone keys ("p0.play"), so
        // a card can say "when this enters play" without naming a seat.
        'card.entered_zone' => ['card', 'from', 'to', 'controller', 'position'],
        'card.left_zone' => ['card', 'from', 'to', 'controller'],
        'card.destroyed' => ['card', 'from', 'source'],
        'card.exhausted' => ['card'],
        'card.readied' => ['card'],
        'card.revealed' => ['card', 'from', 'to', 'player'],
        'card.attached' => ['card', 'host'],
        'damage.dealt' => ['target', 'amount', 'source'],
        'damage.prevented' => ['target', 'amount', 'source'],
        'counter.added' => ['card', 'counter', 'amount'],
        'counter.removed' => ['card', 'counter', 'amount'],
        'resource.gained' => ['player', 'resource', 'amount'],
        'resource.paid' => ['player', 'resource', 'amount'],
        'cards.drawn' => ['player', 'count', 'cards'],
        'attack.declared' => ['attacker', 'defender', 'target'],
        'block.declared' => ['blocker', 'attacker'],
        'combat.resolved' => ['attacks'],
        'ability.resolved' => ['card', 'ability'],
        'player.lost' => ['player', 'reason'],
        'game.ended' => ['winners', 'losers', 'reason'],

        // Beyond doc 06's list, and emitted by the kernel because games need them: a card
        // turning over is the signature beat of a double-sided game, and a draw from an
        // empty deck is what a "deck out" win condition listens for — without the event it
        // would have nothing to fire on.
        'card.flipped' => ['card', 'from', 'to'],
        'card.replaced' => ['card', 'with'],
        'deck.exhausted' => ['player'],
        'zone.shuffled' => ['zone', 'player'],
        'modifiers.expired' => ['duration', 'count'],
        'first_player.set' => ['player'],
    ];

    public static function isCore(string $type): bool
    {
        return isset(self::EVENTS[$type]);
    }

    /** @return list<string> */
    public static function payloadKeys(string $type): array
    {
        return self::EVENTS[$type] ?? [];
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::EVENTS);
    }
}
