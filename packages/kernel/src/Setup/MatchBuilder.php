<?php

declare(strict_types=1);

namespace Gmd\Kernel\Setup;

use Gmd\Kernel\Contract\MatchSetup;
use Gmd\Kernel\Contract\ScenarioSetup;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Diagnostics\BadDocument;
use Gmd\Kernel\State\AdversaryState;
use Gmd\Kernel\State\GameState;
use Gmd\Kernel\State\Instance;
use Gmd\Kernel\State\PlayerState;
use Gmd\Kernel\State\ProgramRef;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\System\ResourceDefinition;
use Gmd\Kernel\System\SystemDocument;

/**
 * Builds the position a match starts from.
 *
 * Instance ids are allocated here, before anything is shuffled, and in deck-document order:
 * `i-p0-1` is that seat's identity, then its deck from the top of the list. Allocating
 * before the shuffle means the ids do not depend on the seed, so two matches of the same
 * decks are directly comparable and a state dump is readable by eye.
 *
 * A cooperative table gets a second half: the scenario puts the villain and its main
 * scheme on the board and stacks the encounter deck, allocating after the seats so that
 * `i-warden-1` reads as plainly as `i-p0-1` (doc 16 §3).
 *
 * The game's own `setup` script does the rest — shuffling, opening hands, deciding who goes
 * first — because those are rules, and rules live in the system document.
 */
final class MatchBuilder
{
    public function build(SystemDocument $system, MatchSetup $setup): GameState
    {
        if ($setup->seats === []) {
            throw BadDocument::because('a match needs at least one seat');
        }
        if ($setup->seatCount() < $system->minPlayers() || $setup->seatCount() > $system->maxPlayers()) {
            throw BadDocument::because(sprintf(
                '%s is for %d-%d players, but %d seats were given',
                $system->name,
                $system->minPlayers(),
                $system->maxPlayers(),
                $setup->seatCount(),
            ));
        }

        $zones = $this->emptyZones($system, $setup);
        $instances = [];
        $players = [];
        $ordinals = [];

        foreach ($setup->seats as $seat) {
            $side = $seat->side();
            $identityInstance = null;

            $identityCode = $seat->identityCode();
            if ($identityCode !== null) {
                $identityInstance = $this->allocate($side, $ordinals);
                $zoneId = (string) ($system->deckbuilding['identity']['zone'] ?? 'play');
                $zoneKey = $system->qualifiedZone($side, $zoneId);
                $instances[$identityInstance] = new Instance(
                    $identityInstance,
                    $identityCode,
                    $side,
                    $side,
                    $zoneKey,
                );
                $zones[$zoneKey][] = $identityInstance;
            }

            foreach ($seat->cardCodes() as $code) {
                if (! $system->cards->has($code)) {
                    throw BadDocument::because("deck for seat {$seat->seat} names unknown card \"{$code}\"");
                }
                $id = $this->allocate($side, $ordinals);
                $zoneKey = $system->qualifiedZone($side, 'deck');
                $instances[$id] = new Instance($id, $code, $side, $side, $zoneKey);
                $zones[$zoneKey][] = $id;
            }

            $players[] = new PlayerState(
                $seat->seat,
                $this->startingResources($system),
                identityInstance: $identityInstance,
            );
        }

        $adversaries = [];
        if ($setup->scenario !== null) {
            $adversaries = $this->buildAdversary(
                $system,
                $setup->scenario,
                $zones,
                $instances,
                $ordinals,
            );
        } elseif ($system->adversaries !== []) {
            throw BadDocument::because(sprintf(
                '%s is played against %s, so a match needs a scenario to say which one',
                $system->name,
                implode(' or ', array_keys($system->adversaries)),
            ), ['adversaries' => array_keys($system->adversaries)]);
        }

        $first = $system->firstStep();

        $vars = [];
        foreach ($ordinals as $side => $count) {
            $vars['__ordinal.instance.' . $side] = $count;
        }
        foreach ($setup->config as $key => $value) {
            $vars['config.' . $key] = $value;
        }

        $state = new GameState(
            systemId: $system->id,
            systemVersion: $system->version,
            systemDigest: $system->digest,
            seed: $setup->seed,
            rngPosition: 0,
            version: 0,
            round: 1,
            phase: $first->phaseId,
            step: $first->id,
            activeSeat: $setup->seats[0]->seat,
            firstSeat: $setup->seats[0]->seat,
            players: $players,
            zones: $zones,
            instances: $instances,
            adversaries: $adversaries,
            vars: $vars,
        );

        return $system->hasSetup ? $this->queueSetup($state) : $state;
    }

    /**
     * The setup script goes on the stack rather than being run here.
     *
     * It is written in the same effect language as everything else and may raise choices —
     * a mulligan is a choice — so it belongs in the settle loop like any other effect.
     */
    private function queueSetup(GameState $state): GameState
    {
        return $state->with([
            'stack' => [new StackItem(
                id: 's1',
                kind: StackItem::KIND_SETUP,
                controller: Side::player($state->activeSeat),
                frames: [new StackFrame(ProgramRef::system('setup'))],
                bindings: ['you' => Side::player($state->activeSeat)],
            )],
            'vars' => [...$state->vars, '__ordinal.stack' => 1],
        ]);
    }

    /**
     * Every zone every side has, created empty.
     *
     * Created up front so that "the zone is empty" and "the zone does not exist" are never
     * confused, and so a query over a zone nobody has used yet reads as empty rather than
     * throwing.
     *
     * @return array<string, list<string>>
     */
    private function emptyZones(SystemDocument $system, MatchSetup $setup): array
    {
        $zones = [];

        foreach ($system->zones as $zone) {
            if ($zone->isShared()) {
                $zones[Side::zoneKey(Side::SHARED, $zone->id)] = [];

                continue;
            }
            if ($zone->side !== null) {
                $zones[Side::zoneKey($zone->side, $zone->id)] = [];

                continue;
            }
            foreach ($setup->seats as $seat) {
                $zones[Side::zoneKey($seat->side(), $zone->id)] = [];
            }
        }

        return $zones;
    }

    /** @return array<string, int> */
    private function startingResources(SystemDocument $system): array
    {
        $resources = [];
        foreach ($system->resources as $resource) {
            /** @var ResourceDefinition $resource */
            $resources[$resource->id] = $resource->start;
        }

        return $resources;
    }

    /**
     * Put the scenario on the table: anchors into their zones, encounter sets into the deck.
     *
     * The anchors are checked against what the adversary declares rather than taken on
     * trust, because a scenario naming a card of the wrong type — a scheme where the villain
     * goes — would otherwise fail much later, as an attribute lookup deep inside an
     * activation script, with nothing pointing back at the scenario document.
     *
     * @param  array<string, list<string>>  $zones
     * @param  array<string, Instance>  $instances
     * @param  array<string, int>  $ordinals
     * @return array<string, AdversaryState>
     */
    private function buildAdversary(
        SystemDocument $system,
        ScenarioSetup $scenario,
        array &$zones,
        array &$instances,
        array &$ordinals,
    ): array {
        $definition = $system->adversary($scenario->adversary) ?? throw BadDocument::because(
            "scenario names adversary \"{$scenario->adversary}\", which system {$system->id} does not define",
            ['adversary' => $scenario->adversary, 'defined' => array_keys($system->adversaries)],
        );

        $side = $definition->id;

        foreach (array_keys($scenario->anchors) as $anchorId) {
            if ($definition->anchor($anchorId) === null) {
                throw BadDocument::because(
                    "scenario fills anchor \"{$anchorId}\", which adversary \"{$side}\" does not declare",
                    ['anchor' => $anchorId],
                );
            }
        }

        $anchors = [];
        foreach ($definition->anchors as $anchor) {
            $code = $scenario->anchors[$anchor['id']] ?? null;
            if ($code === null) {
                if ($anchor['required'] ?? true) {
                    throw BadDocument::because(
                        "scenario leaves required anchor \"{$anchor['id']}\" of adversary \"{$side}\" empty",
                        ['anchor' => $anchor['id']],
                    );
                }

                continue;
            }

            if (! $system->cards->has($code)) {
                throw BadDocument::because("anchor \"{$anchor['id']}\" names unknown card \"{$code}\"");
            }

            $printedType = $system->cards->get($code)->face()->type;
            if ($printedType !== $anchor['type']) {
                throw BadDocument::because(sprintf(
                    'anchor "%s" wants a %s, but "%s" is a %s',
                    $anchor['id'],
                    $anchor['type'],
                    $code,
                    $printedType,
                ), ['anchor' => $anchor['id'], 'card' => $code]);
            }

            $zoneId = $anchor['zone'] ?? throw BadDocument::because(
                "anchor \"{$anchor['id']}\" of adversary \"{$side}\" declares no zone, so its card has nowhere to go",
                ['anchor' => $anchor['id']],
            );
            $zoneKey = $system->qualifiedZone($side, $zoneId);

            $id = $this->allocate($side, $ordinals);
            $instances[$id] = new Instance($id, $code, $side, $side, $zoneKey);
            $zones[$zoneKey][] = $id;
            $anchors[$anchor['id']] = $id;
        }

        if ($scenario->encounterCards !== []) {
            $deckZone = $definition->encounterDeck ?? throw BadDocument::because(
                "adversary \"{$side}\" is given encounter sets but declares no encounterDeck zone to shuffle them into",
                ['adversary' => $side],
            );
            $zoneKey = $system->qualifiedZone($side, $deckZone);

            foreach ($scenario->encounterCards as $code) {
                if (! $system->cards->has($code)) {
                    throw BadDocument::because("encounter set names unknown card \"{$code}\"");
                }
                $id = $this->allocate($side, $ordinals);
                $instances[$id] = new Instance($id, $code, $side, $side, $zoneKey);
                $zones[$zoneKey][] = $id;
            }
        }

        return [$side => new AdversaryState($side, $anchors)];
    }

    /** @param array<string, int> $ordinals */
    private function allocate(string $side, array &$ordinals): string
    {
        $ordinals[$side] = ($ordinals[$side] ?? 0) + 1;

        return 'i-' . $side . '-' . $ordinals[$side];
    }
}
