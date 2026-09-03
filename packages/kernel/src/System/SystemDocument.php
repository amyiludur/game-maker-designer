<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Diagnostics\BadDocument;

/**
 * A whole game, compiled: the rules structure plus every card authored against it.
 *
 * This is the object the kernel actually plays. It is built once per (system version, card
 * set) and shared across every match in a simulation batch, so anything that can be
 * computed ahead of time — resolved keywords, the trigger index, the program table — is
 * computed here rather than ten thousand times in the settle loop.
 */
final readonly class SystemDocument
{
    /**
     * @param  array<string, ResourceDefinition>  $resources
     * @param  array<string, CounterDefinition>  $counters
     * @param  array<string, ZoneDefinition>  $zones
     * @param  array<string, CardTypeDefinition>  $cardTypes
     * @param  array<string, KeywordDefinition>  $keywords
     * @param  array<string, ActionTemplate>  $actions
     * @param  list<PhaseDefinition>  $phases
     * @param  list<StateCheckDefinition>  $stateChecks
     * @param  list<WinConditionDefinition>  $winConditions
     * @param  array<string, AdversaryDefinition>  $adversaries
     * @param  array<string, list<CompiledAbility>>  $triggerIndex  event type => abilities that watch it
     * @param  array<string, mixed>  $vocabularies
     * @param  array<string, mixed>  $players
     * @param  array<string, mixed>  $deckbuilding
     * @param  array<string, mixed>  $scenarioBuilding
     * @param  array<string, mixed>  $mulligan
     * @param  array<string, mixed>  $ui
     * @param  list<string>  $declaredEvents  events this game emits beyond the core catalogue
     * @param  array<string, true>  $continuousCodes  card codes carrying a static ability
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $name,
        public string $digest,
        public array $players,
        public array $resources,
        public array $counters,
        public array $zones,
        public array $cardTypes,
        public array $keywords,
        public array $actions,
        public array $phases,
        public array $stateChecks,
        public array $winConditions,
        public array $adversaries,
        public array $vocabularies,
        public array $deckbuilding,
        public array $scenarioBuilding,
        public array $mulligan,
        public array $ui,
        public CardDatabase $cards,
        public ProgramTable $programs,
        public array $triggerIndex,
        public array $declaredEvents = [],
        public string $structure = 'phased',
        public string $firstPlayerRule = 'alternate',
        public string $triggerOrdering = 'apnap',
        public bool $hasSetup = false,
        public array $continuousCodes = [],
    ) {}

    public function mode(): string
    {
        return (string) ($this->players['mode'] ?? 'competitive');
    }

    public function minPlayers(): int
    {
        return (int) ($this->players['min'] ?? 2);
    }

    public function maxPlayers(): int
    {
        return (int) ($this->players['max'] ?? 2);
    }

    public function zone(string $id): ZoneDefinition
    {
        return $this->zones[$id] ?? throw BadDocument::because("no zone \"{$id}\" in system {$this->id}");
    }

    public function hasZone(string $id): bool
    {
        return isset($this->zones[$id]);
    }

    /** Shared zones live under the "shared" side no matter who is asking. */
    public function qualifiedZone(string $side, string $zoneId): string
    {
        return Side::zoneKey($this->zone($zoneId)->isShared() ? Side::SHARED : $side, $zoneId);
    }

    public function cardType(string $id): CardTypeDefinition
    {
        return $this->cardTypes[$id] ?? throw BadDocument::because("no card type \"{$id}\" in system {$this->id}");
    }

    public function hasCardType(string $id): bool
    {
        return isset($this->cardTypes[$id]);
    }

    public function keyword(string $id): ?KeywordDefinition
    {
        return $this->keywords[$id] ?? null;
    }

    public function action(string $id): ActionTemplate
    {
        return $this->actions[$id] ?? throw BadDocument::because("no action \"{$id}\" in system {$this->id}");
    }

    /** @return list<ActionTemplate> */
    public function actionsForWindow(string $qualifiedStep): array
    {
        $window = $this->step($qualifiedStep)?->window;

        $available = [];
        foreach ($this->actions as $action) {
            if (! $action->isAvailableIn($qualifiedStep)) {
                continue;
            }
            if ($window !== null && ! $window->allows($action->id)) {
                continue;
            }
            $available[] = $action;
        }

        return $available;
    }

    public function phase(string $id): ?PhaseDefinition
    {
        foreach ($this->phases as $phase) {
            if ($phase->id === $id) {
                return $phase;
            }
        }

        return null;
    }

    public function step(string $qualifiedStep): ?StepDefinition
    {
        foreach ($this->phases as $phase) {
            foreach ($phase->steps as $step) {
                if ($step->qualifiedId() === $qualifiedStep) {
                    return $step;
                }
            }
        }

        return null;
    }

    /** @return list<StepDefinition> every step in round order, so the phase rail can be generated */
    public function steps(): array
    {
        $steps = [];
        foreach ($this->phases as $phase) {
            foreach ($phase->steps as $step) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    public function firstStep(): StepDefinition
    {
        return $this->steps()[0] ?? throw BadDocument::because("system {$this->id} declares no steps");
    }

    /** The step that follows this one, or null at the end of the round. */
    public function stepAfter(string $qualifiedStep): ?StepDefinition
    {
        $steps = $this->steps();
        foreach ($steps as $i => $step) {
            if ($step->qualifiedId() === $qualifiedStep) {
                return $steps[$i + 1] ?? null;
            }
        }

        return null;
    }

    public function adversary(string $id): ?AdversaryDefinition
    {
        return $this->adversaries[$id] ?? null;
    }

    /** @return list<CompiledAbility> */
    public function abilitiesTriggeredBy(string $event): array
    {
        return $this->triggerIndex[$event] ?? [];
    }

    /**
     * Card codes carrying a static or constant ability, as a lookup set.
     *
     * Continuous effects are derived from the board on every read, so this is asked once
     * per instance per rebuild. Most cards have none.
     *
     * @return array<string, true>
     */
    public function continuousAbilityCodes(): array
    {
        return $this->continuousCodes;
    }

    /** @return list<string> trait names this game recognises */
    public function traits(): array
    {
        return $this->vocabularies['traits'] ?? [];
    }

    public function isCooperative(): bool
    {
        return $this->mode() === 'cooperative';
    }
}
