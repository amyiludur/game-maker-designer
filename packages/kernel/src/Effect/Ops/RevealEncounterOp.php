<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Diagnostics\BadDocument;
use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Turn over the top of an encounter deck and put it where its type says it goes.
 *
 * This is the co-op format's engine of pressure, and the reason it is a primitive rather
 * than a script: revealing is one beat with three different outcomes depending on what
 * comes up — a minion engages someone, a treachery resolves and is discarded, a card with
 * a "when revealed" ability does that first. A game should not have to write that branch,
 * and every co-op game would write the same one.
 *
 * Where a card goes is read from its card type's `playableTo` rather than from its name, so
 * the kernel never learns the words "minion" or "treachery" (doc 16 §4). A type that can be
 * played to the engagement zone engages; anything else goes to the discard, which is where
 * a treachery belongs once it has done its work.
 *
 * `card.revealed` is emitted after the move, so a "when revealed" ability sees the card
 * already in the zone it will act from, and its `$event.player` is the player who revealed
 * it — which is how a minion's ability can hit only that player's board.
 */
final class RevealEncounterOp implements Op
{
    public function id(): string
    {
        return 'reveal_encounter';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('from', 'string', 'The encounter deck, as a side-qualified zone key.')
            ->optional('discard', 'string', 'Where spent encounter cards rest, and what a reshuffle draws back.')
            ->optional('to', 'string', 'The zone a card that stays on the table goes to.')
            ->optional('zonePlayer', 'selector', 'Whose copy of that zone, and who counts as having revealed it.')
            ->optional('count', 'expression')
            ->optional('shuffleOnEmpty', 'boolean', 'Reshuffle the discard back in rather than stopping.');
    }

    public function execute(array $node, OpContext $context): void
    {
        $fromKey = $this->zoneKey($context, (string) $node['from'], 'from');
        $discardKey = isset($node['discard'])
            ? $this->zoneKey($context, (string) $node['discard'], 'discard')
            : null;

        [$adversary] = Side::splitZoneKey($fromKey);
        $player = isset($node['zonePlayer'])
            ? $context->side($node['zonePlayer'])
            : $context->draft->activeSide();

        $count = $context->evaluateInt($node['count'] ?? null, 1);
        $reshuffles = ($node['shuffleOnEmpty'] ?? true) === true;

        for ($i = 0; $i < $count; $i++) {
            $card = $this->topCard($context, $fromKey, $discardKey, $reshuffles, $adversary);
            if ($card === null) {
                return;
            }

            $this->place($context, $node, $card, $player, $adversary, $discardKey);

            $context->emit('card.revealed', [
                'card' => $card,
                'player' => $player,
                'adversary' => $adversary,
            ], $card);
        }
    }

    /**
     * The top card, reshuffling the discard back in if the deck has run out.
     *
     * A deck that cannot be refilled emits `deck.exhausted` and stops rather than throwing:
     * running out of encounter cards is a position a scenario can legitimately reach, and a
     * win condition may well be listening for it.
     */
    private function topCard(
        OpContext $context,
        string $fromKey,
        ?string $discardKey,
        bool $reshuffles,
        string $adversary,
    ): ?string {
        $deck = $context->draft->zone($fromKey);

        if ($deck === [] && $reshuffles && $discardKey !== null) {
            $discarded = $context->draft->zone($discardKey);
            foreach ($discarded as $card) {
                CardMovement::move($context, $card, $fromKey, faceDown: true);
            }
            if ($discarded !== []) {
                $context->draft->setZone($fromKey, $context->draft->rng()->shuffle($context->draft->zone($fromKey)));
                $context->emit('encounter_deck.reshuffled', [
                    'adversary' => $adversary,
                    'count' => count($discarded),
                ]);
            }
            $deck = $context->draft->zone($fromKey);
        }

        if ($deck === []) {
            $context->emit('deck.exhausted', ['player' => $adversary, 'zone' => $fromKey]);

            return null;
        }

        return $deck[0];
    }

    /**
     * Put the revealed card where its type says it lives.
     *
     * A card that stays on the table is held in the revealing player's zone but stays the
     * adversary's to control — that split is what makes "attack an enemy" a query over the
     * adversary's cards while the board still shows whose face they are in.
     *
     * @param  array<string, mixed>  $node
     */
    private function place(
        OpContext $context,
        array $node,
        string $card,
        string $player,
        string $adversary,
        ?string $discardKey,
    ): void {
        $engagement = isset($node['to']) ? (string) $node['to'] : null;
        $type = $context->system->cardType(
            $context->system->cards->get($context->draft->instance($card)->code)->face()->type,
        );

        if ($engagement !== null && in_array($engagement, $type->playableTo, true)) {
            CardMovement::move(
                $context,
                $card,
                $engagement,
                side: $player,
                faceDown: false,
                controller: $adversary,
            );
            $context->emit('card.engaged', ['card' => $card, 'player' => $player]);

            return;
        }

        $rest = $type->playableTo[0] ?? $discardKey ?? throw BadDocument::because(
            "revealed card \"{$card}\" has a type that declares no playableTo zone, and no discard was given",
            ['card' => $card],
        );

        CardMovement::move($context, $card, $rest, side: $adversary, faceDown: false, controller: $adversary);
    }

    /** Resolve a zone reference that may or may not name its side. */
    private function zoneKey(OpContext $context, string $reference, string $param): string
    {
        [$side, $zoneId] = CardMovement::splitDestination($context, $reference);
        if ($side !== null) {
            return Side::zoneKey($side, $zoneId);
        }

        throw BadDocument::because(
            "reveal_encounter's \"{$param}\" must name the side holding the zone, as in \"warden.{$reference}\"",
            ['zone' => $reference],
        );
    }
}
