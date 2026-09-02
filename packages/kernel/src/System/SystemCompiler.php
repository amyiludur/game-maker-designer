<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\Diagnostics\CompileError;
use Gmd\Kernel\State\Codec\CanonicalJson;
use Gmd\Kernel\State\ProgramRef;

/**
 * Turns the authored JSON into the object the kernel plays.
 *
 * Compilation is pure and cached, and it earns its place by doing three things once that
 * would otherwise be done in every match of a ten-thousand-match batch:
 *
 *  1. Keyword expansion. A card that says `Bolster 2` comes out the other side with a full
 *     triggered ability and `n` bound to 2, so the interpreter never learns what a keyword
 *     is.
 *  2. The trigger index. Without it, every emitted event scans every instance's every
 *     ability; Emberfall emits around fifteen events per action.
 *  3. The program table. Every effect script gets a stable reference, so the stack can
 *     point at scripts instead of copying them.
 *
 * It also fails early. An op the kernel does not implement is a compile error here, not an
 * UnknownOp at round 12 of match 7,431.
 */
final class SystemCompiler
{
    /** @var array<string, list<mixed>> */
    private array $programs = [];

    /** @var array<string, list<CompiledAbility>> */
    private array $triggerIndex = [];

    /** @var array<string, true> */
    private array $continuousCodes = [];

    /**
     * @param  array<string, mixed>  $system  the game-system document
     * @param  list<array<string, mixed>>  $sets  set documents, each with a `cards` list
     */
    public function compile(array $system, array $sets = []): SystemDocument
    {
        $this->programs = [];
        $this->triggerIndex = [];
        $this->continuousCodes = [];

        foreach (['id', 'version', 'name', 'zones', 'cardTypes', 'round', 'winConditions'] as $required) {
            if (! isset($system[$required])) {
                throw CompileError::because("game system is missing required field \"{$required}\"");
            }
        }

        $zones = $this->index($system['zones'] ?? [], ZoneDefinition::fromArray(...));
        $cardTypes = $this->index($system['cardTypes'] ?? [], CardTypeDefinition::fromArray(...));
        $keywords = $this->index($system['keywords'] ?? [], KeywordDefinition::fromArray(...));
        $resources = $this->index($system['resources'] ?? [], ResourceDefinition::fromArray(...));
        $counters = $this->index($system['counters'] ?? [], CounterDefinition::fromArray(...));
        $adversaries = $this->index($system['adversaries'] ?? [], AdversaryDefinition::fromArray(...));

        $actions = [];
        foreach ($system['actions'] ?? [] as $raw) {
            $action = ActionTemplate::fromArray($raw);
            $actions[$action->id] = $action;
            $this->addProgram($action->effectProgram(), $raw['effect'] ?? []);
            if ($action->hasCost) {
                $this->addProgram($action->costProgram(), $raw['cost']);
            }
            $this->addProgram($action->playProgram(), $this->playScript($action, $raw));
        }

        $round = $system['round'];
        $phases = [];
        foreach ($round['phases'] as $rawPhase) {
            $phase = PhaseDefinition::fromArray($rawPhase);
            $phases[] = $phase;
            foreach ($rawPhase['steps'] as $rawStep) {
                $step = StepDefinition::fromArray($phase->id, $rawStep);
                if ($step->hasAuto) {
                    $this->addProgram($step->autoProgram(), $rawStep['auto']);
                }
                if ($step->hasAuto && $step->isWindow()) {
                    throw CompileError::because(
                        "step \"{$step->qualifiedId()}\" declares both an automatic script and a window; "
                        . 'a step is one or the other',
                    );
                }
                if (! $step->hasAuto && ! $step->isWindow()) {
                    throw CompileError::because(
                        "step \"{$step->qualifiedId()}\" declares neither an automatic script nor a window, "
                        . 'so nothing would ever happen in it',
                    );
                }
            }
        }

        $stateChecks = [];
        foreach ($system['stateChecks'] ?? [] as $raw) {
            $check = StateCheckDefinition::fromArray($raw);
            $stateChecks[] = $check;
            $this->addProgram($check->thenProgram(), $raw['then'] ?? []);
        }

        $winConditions = array_map(
            WinConditionDefinition::fromArray(...),
            $system['winConditions'] ?? [],
        );

        foreach ($system['adversaries'] ?? [] as $raw) {
            $adversary = AdversaryDefinition::fromArray($raw);
            if ($adversary->hasActivation) {
                $this->addProgram($adversary->activationProgram(), $raw['activation']);
            }
        }

        $hasSetup = isset($system['setup']) && $system['setup'] !== [];
        if ($hasSetup) {
            $this->addProgram(ProgramRef::system('setup'), $system['setup']);
        }

        // Keyword-granted ability bodies are compiled once and shared by every card that
        // carries the keyword; only the parameter bindings differ per card.
        foreach ($keywords as $keyword) {
            foreach ($keyword->grants as $grant) {
                if (($grant['kind'] ?? '') === 'ability' && isset($grant['ability'])) {
                    $this->addAbilityPrograms(
                        "keyword:{$keyword->id}#{$grant['ability']['id']}",
                        $grant['ability'],
                    );
                }
            }
        }

        $cards = $this->compileCards($sets, $cardTypes, $keywords);

        $document = new SystemDocument(
            id: (string) $system['id'],
            version: (string) $system['version'],
            name: (string) $system['name'],
            digest: $this->digest($system, $sets),
            players: $system['players'] ?? ['min' => 2, 'max' => 2, 'mode' => 'competitive'],
            resources: $resources,
            counters: $counters,
            zones: $zones,
            cardTypes: $cardTypes,
            keywords: $keywords,
            actions: $actions,
            phases: $phases,
            stateChecks: $stateChecks,
            winConditions: $winConditions,
            adversaries: $adversaries,
            vocabularies: $system['vocabularies'] ?? [],
            deckbuilding: $system['deckbuilding'] ?? [],
            mulligan: $system['mulligan'] ?? [],
            ui: $system['ui'] ?? [],
            cards: $cards,
            programs: new ProgramTable($this->programs),
            triggerIndex: $this->triggerIndex,
            declaredEvents: $system['events'] ?? [],
            structure: (string) ($round['structure'] ?? 'phased'),
            firstPlayerRule: (string) ($round['firstPlayer']['rule'] ?? 'alternate'),
            triggerOrdering: (string) ($round['triggerOrdering'] ?? 'apnap'),
            hasSetup: $hasSetup,
            continuousCodes: $this->continuousCodes,
        );

        return $document;
    }

    /**
     * @param  list<array<string, mixed>>  $sets
     * @param  array<string, CardTypeDefinition>  $cardTypes
     * @param  array<string, KeywordDefinition>  $keywords
     */
    private function compileCards(array $sets, array $cardTypes, array $keywords): CardDatabase
    {
        $cards = [];
        foreach ($sets as $set) {
            foreach ($set['cards'] ?? [] as $raw) {
                $code = (string) $raw['code'];
                if (isset($cards[$code])) {
                    throw CompileError::because("card code \"{$code}\" is defined twice");
                }
                $cards[$code] = $this->compileCard($raw, $set['code'] ?? null, $cardTypes, $keywords);
            }
        }
        ksort($cards);

        return new CardDatabase($cards);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, CardTypeDefinition>  $cardTypes
     * @param  array<string, KeywordDefinition>  $keywords
     */
    private function compileCard(array $raw, ?string $setId, array $cardTypes, array $keywords): CardDefinition
    {
        $code = (string) $raw['code'];

        // A card is either flat or double-sided; normalise both into a face map so nothing
        // downstream has to ask which shape it is looking at.
        $rawFaces = $raw['sides'] ?? ['front' => $raw];

        $faces = [];
        foreach ($rawFaces as $faceName => $rawFace) {
            $faces[(string) $faceName] = $this->compileFace(
                $code,
                (string) $faceName,
                $rawFace,
                $cardTypes,
                $keywords,
            );
        }

        return new CardDefinition(
            $code,
            $faces,
            $raw['faction'] ?? null,
            $raw['rarity'] ?? null,
            $setId,
            (int) ($raw['quantity'] ?? 1),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, CardTypeDefinition>  $cardTypes
     * @param  array<string, KeywordDefinition>  $keywords
     */
    private function compileFace(
        string $code,
        string $faceName,
        array $raw,
        array $cardTypes,
        array $keywords,
    ): CardFace {
        $type = (string) ($raw['type'] ?? '');
        if (! isset($cardTypes[$type])) {
            throw CompileError::because("card \"{$code}\" ({$faceName}) has unknown card type \"{$type}\"");
        }

        $abilities = [];
        $seen = [];
        foreach ($raw['abilities'] ?? [] as $rawAbility) {
            $ability = $this->compileAbility($rawAbility, $code, $faceName);
            $seen[$ability->id] = true;
            $abilities[] = $ability;
            $this->addAbilityPrograms("card:{$code}@{$faceName}#{$ability->id}", $rawAbility);
            $this->indexTrigger($ability);
        }

        $permissions = [];
        $restrictions = [];
        foreach ($raw['keywords'] ?? [] as $carried) {
            $keywordId = (string) $carried['id'];
            $keyword = $keywords[$keywordId] ?? throw CompileError::because(
                "card \"{$code}\" ({$faceName}) carries unknown keyword \"{$keywordId}\"",
            );
            $params = $carried['params'] ?? [];

            foreach ($keyword->parameterIds() as $parameterId) {
                if (! array_key_exists($parameterId, $params)) {
                    throw CompileError::because(
                        "card \"{$code}\" ({$faceName}) carries keyword \"{$keywordId}\" "
                        . "without required parameter \"{$parameterId}\"",
                    );
                }
            }

            foreach ($keyword->grants as $grant) {
                $kind = (string) ($grant['kind'] ?? '');

                if ($kind === 'permission') {
                    $permissions[(string) $grant['permission']] = (bool) ($grant['value'] ?? true);
                } elseif ($kind === 'restriction') {
                    $restrictions[(string) $grant['restriction']] = (bool) ($grant['value'] ?? true);
                } elseif ($kind === 'ability') {
                    $ability = $this->compileAbility(
                        $grant['ability'],
                        $code,
                        $faceName,
                        params: $params,
                        keywordId: $keywordId,
                    );
                    if (isset($seen[$ability->id])) {
                        throw CompileError::because(
                            "card \"{$code}\" ({$faceName}) has two abilities with id \"{$ability->id}\"; "
                            . "keyword \"{$keywordId}\" collides with an ability authored on the card",
                        );
                    }
                    $seen[$ability->id] = true;
                    $abilities[] = $ability;
                    $this->indexTrigger($ability);
                } else {
                    throw CompileError::because(
                        "keyword \"{$keywordId}\" has a grant with no recognised kind",
                    );
                }
            }
        }

        return new CardFace(
            $faceName,
            (string) ($raw['name'] ?? $code),
            $type,
            $raw['attributes'] ?? [],
            $raw['keywords'] ?? [],
            $abilities,
            $permissions,
            $restrictions,
            $raw['textOverride'] ?? $raw['text'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $params
     */
    private function compileAbility(
        array $raw,
        string $ownerCode,
        string $face,
        array $params = [],
        ?string $keywordId = null,
    ): CompiledAbility {
        $kind = (string) ($raw['kind'] ?? '');
        if ($kind === '') {
            throw CompileError::because("card \"{$ownerCode}\" has an ability with no kind");
        }
        if (in_array($kind, [CompiledAbility::KIND_TRIGGERED, CompiledAbility::KIND_REPLACEMENT], true)
            && ! isset($raw['trigger'])) {
            throw CompileError::because(
                "card \"{$ownerCode}\" ability \"{$raw['id']}\" is {$kind} but declares no trigger",
            );
        }

        return new CompiledAbility(
            id: (string) $raw['id'],
            kind: $kind,
            ownerCode: $ownerCode,
            face: $face,
            speed: (string) ($raw['speed'] ?? 'action'),
            trigger: $raw['trigger'] ?? null,
            activeWhile: $raw['activeWhile'] ?? null,
            targets: $raw['targets'] ?? [],
            requirements: $raw['requirements'] ?? [],
            limit: $raw['limit'] ?? [],
            params: $params,
            text: $raw['text'] ?? null,
            hasCost: isset($raw['cost']) && $raw['cost'] !== [],
            hasEffect: isset($raw['effect']) && $raw['effect'] !== [],
            keywordId: $keywordId,
        );
    }

    /**
     * An action, as one script: pay, then do, then announce.
     *
     * Compiling the three parts into a single program is what makes taking an action a
     * single resumable stack item. Costs must be paid before the effect runs, and the
     * action's `emits` must fire after it — an interpreter that had to coordinate three
     * separate items to get that order right would be one more thing to get wrong.
     *
     * The convention for `emits`: a target named `card` is the card the action is about,
     * and is carried in the event payload. That is what lets "when you play a card"
     * triggers work without every action emitting the event by hand.
     *
     * @param  array<string, mixed>  $raw
     * @return list<mixed>
     */
    private function playScript(ActionTemplate $action, array $raw): array
    {
        $script = [...($raw['cost'] ?? []), ...($raw['effect'] ?? [])];

        $namesCard = false;
        foreach ($action->targets as $target) {
            if (($target['id'] ?? null) === 'card') {
                $namesCard = true;
            }
        }

        foreach ($action->emits as $event) {
            $payload = ['player' => '$you', 'action' => $action->id];
            if ($namesCard) {
                $payload['card'] = '$target.card';
            }
            $script[] = ['op' => 'emit', 'type' => $event, 'payload' => $payload];
        }

        return $script;
    }

    /**
     * Register an ability's scripts.
     *
     * An ability with targets also gets a `.resolve` program: its target selection compiled
     * in front of its effect. Choosing targets is then an ordinary op sequence, which means
     * it can raise a choice, park, and resume like anything else — instead of the trigger
     * queue needing its own copy of that machinery.
     *
     * @param  array<string, mixed>  $raw
     */
    private function addAbilityPrograms(string $prefix, array $raw): void
    {
        $effect = $raw['effect'] ?? [];

        if ($effect !== []) {
            $this->addProgram(new ProgramRef($prefix . '.effect'), $effect);
        }
        if (isset($raw['cost']) && $raw['cost'] !== []) {
            $this->addProgram(new ProgramRef($prefix . '.cost'), $raw['cost']);
        }

        $targets = $raw['targets'] ?? [];
        if ($targets !== []) {
            $selection = array_map(
                static fn (array $target): array => [
                    'op' => 'select_target',
                    'id' => $target['id'],
                    'query' => $target['query'] ?? [],
                    'count' => $target['count'] ?? 1,
                    'optional' => $target['optional'] ?? false,
                    'chooser' => $target['chooser'] ?? '$you',
                    'prompt' => $target['prompt'] ?? 'Choose a target',
                ],
                $targets,
            );
            $this->addProgram(new ProgramRef($prefix . '.resolve'), [...$selection, ...$effect]);
        }
    }

    private function indexTrigger(CompiledAbility $ability): void
    {
        $event = $ability->triggerEvent();
        if ($event !== null) {
            $this->triggerIndex[$event][] = $ability;
        }
        if ($ability->isContinuous() && $ability->hasEffect) {
            $this->continuousCodes[$ability->ownerCode] = true;
        }
    }

    /** @param list<mixed> $ops */
    private function addProgram(ProgramRef $ref, array $ops): void
    {
        $this->programs[$ref->ref] = array_values($ops);
    }

    /**
     * @template T of object
     *
     * @param  list<array<string, mixed>>  $raws
     * @param  callable(array<string, mixed>): T  $factory
     * @return array<string, T>
     */
    private function index(array $raws, callable $factory): array
    {
        $indexed = [];
        foreach ($raws as $raw) {
            $item = $factory($raw);
            /** @var string $id */
            $id = $item->id;
            if (isset($indexed[$id])) {
                throw CompileError::because('duplicate id "' . $id . '" in the game system document');
            }
            $indexed[$id] = $item;
        }

        return $indexed;
    }

    /**
     * A fingerprint of the exact rules a position was reached under.
     *
     * It goes into the conformance hash, so a match cannot silently reproduce against a
     * different version of the cards it was played with.
     *
     * @param  array<string, mixed>  $system
     * @param  list<array<string, mixed>>  $sets
     */
    private function digest(array $system, array $sets): string
    {
        $cards = [];
        foreach ($sets as $set) {
            foreach ($set['cards'] ?? [] as $card) {
                $cards[(string) $card['code']] = $card;
            }
        }
        ksort($cards);

        // $schema keys are editor conveniences and must not move the digest.
        $strip = static function (array $document) use (&$strip): array {
            unset($document['$schema']);
            foreach ($document as $key => $value) {
                if (is_array($value)) {
                    $document[$key] = $strip($value);
                }
            }

            return $document;
        };

        return 'sha256:' . hash('sha256', CanonicalJson::encode([
            'system' => $strip($system),
            'cards' => array_map($strip, $cards),
        ]));
    }
}
