<?php

declare(strict_types=1);

namespace Gmd\Kernel\State\Codec;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Diagnostics\BadDocument;
use Gmd\Kernel\State\AdversaryState;
use Gmd\Kernel\State\EventRecord;
use Gmd\Kernel\State\GameState;
use Gmd\Kernel\State\Instance;
use Gmd\Kernel\State\MatchResult;
use Gmd\Kernel\State\ModifierRecord;
use Gmd\Kernel\State\PlayerState;
use Gmd\Kernel\State\ProgramRef;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\State\TriggerRecord;

/**
 * GameState to and from the JSON in schemas/game-state.schema.json.
 *
 * Used only at process boundaries — Redis, the database, a replay file, the hasher — and
 * never inside the settle loop. A 10,000-match fuzz run that JSON-encoded once per action
 * would spend most of its time in json_encode rather than in the rules.
 *
 * Fields sitting at their default are omitted. That keeps the encoded form small, and more
 * importantly it keeps it canonical: two states that are equal have to encode identically,
 * so there must be exactly one way to write "this card is not exhausted".
 */
final class StateCodec
{
    /** @return array<string, mixed> */
    public static function encode(GameState $state): array
    {
        $out = [
            'systemVersion' => $state->systemVersion,
            'seed' => $state->seed,
            'rngPosition' => $state->rngPosition,
            'version' => $state->version,
            'round' => $state->round,
            'activePlayer' => $state->activeSeat,
            'phase' => $state->phase,
            'step' => $state->step,
            'players' => array_map(self::encodePlayer(...), $state->players),
            'zones' => $state->zones,
            'instances' => self::encodeInstances($state->instances),
        ];

        $out['playerCount'] = count($state->players);
        $out['firstPlayer'] = $state->firstSeat;

        if ($state->priority !== null) {
            $out['priority'] = $state->priority;
        }
        if ($state->consecutivePasses !== 0) {
            $out['consecutivePasses'] = $state->consecutivePasses;
        }
        if ($state->adversaries !== []) {
            $out['adversaries'] = array_map(
                static fn (AdversaryState $a): array => array_filter(
                    ['anchors' => $a->anchors, 'flags' => $a->flags],
                    static fn (array $v): bool => $v !== [],
                ),
                $state->adversaries,
            );
        }
        if ($state->modifiers !== []) {
            $out['modifiers'] = array_map(self::encodeModifier(...), $state->modifiers);
        }
        if ($state->stack !== []) {
            $out['stack'] = array_map(self::encodeStackItem(...), $state->stack);
        }
        if ($state->triggerQueue !== []) {
            $out['triggerQueue'] = array_map(self::encodeTrigger(...), $state->triggerQueue);
        }
        if ($state->pendingChoice !== null) {
            $out['pendingChoice'] = self::encodeChoice($state->pendingChoice);
        }
        $vars = self::pruneEmpty($state->vars);
        if ($vars !== []) {
            $out['vars'] = $vars;
        }
        if ($state->log !== []) {
            $out['log'] = array_map(self::encodeEvent(...), $state->log);
        }
        if ($state->result !== null) {
            $out['result'] = self::encodeResult($state->result);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function decode(array $document, string $systemId = '', string $systemDigest = ''): GameState
    {
        foreach (['systemVersion', 'seed', 'rngPosition', 'version', 'round', 'phase', 'step', 'players', 'zones', 'instances'] as $required) {
            if (! array_key_exists($required, $document)) {
                throw BadDocument::because("game state is missing required field \"{$required}\"");
            }
        }

        /** @var list<array<string, mixed>> $players */
        $players = $document['players'];
        /** @var array<string, list<string>> $zones */
        $zones = $document['zones'];
        /** @var array<string, array<string, mixed>> $instances */
        $instances = $document['instances'];

        $decodedInstances = [];
        foreach ($instances as $id => $raw) {
            $decodedInstances[$id] = new Instance(
                (string) $id,
                (string) $raw['code'],
                (string) $raw['owner'],
                (string) $raw['controller'],
                (string) $raw['zone'],
                (string) ($raw['face'] ?? 'front'),
                (bool) ($raw['exhausted'] ?? false),
                (bool) ($raw['faceDown'] ?? false),
                $raw['counters'] ?? [],
                $raw['attachedTo'] ?? null,
                $raw['attachments'] ?? [],
                (int) ($raw['enteredOnRound'] ?? 0),
                $raw['revealedTo'] ?? [],
                $raw['usedLimits'] ?? [],
            );
        }

        $decodedPlayers = [];
        foreach ($players as $raw) {
            $decodedPlayers[] = new PlayerState(
                (int) $raw['seat'],
                $raw['resources'] ?? [],
                $raw['flags'] ?? [],
                $raw['identityInstance'] ?? null,
                (string) ($raw['status'] ?? 'playing'),
            );
        }

        $adversaries = [];
        /** @var array<string, array<string, mixed>> $rawAdversaries */
        $rawAdversaries = $document['adversaries'] ?? [];
        foreach ($rawAdversaries as $id => $raw) {
            $adversaries[$id] = new AdversaryState((string) $id, $raw['anchors'] ?? [], $raw['flags'] ?? []);
        }

        return new GameState(
            $systemId,
            (string) $document['systemVersion'],
            $systemDigest,
            (int) $document['seed'],
            (int) $document['rngPosition'],
            (int) $document['version'],
            (int) $document['round'],
            (string) $document['phase'],
            (string) $document['step'],
            (int) ($document['activePlayer'] ?? 0),
            (int) ($document['firstPlayer'] ?? 0),
            $decodedPlayers,
            $zones,
            $decodedInstances,
            $adversaries,
            array_map(self::decodeModifier(...), $document['modifiers'] ?? []),
            array_map(self::decodeStackItem(...), $document['stack'] ?? []),
            array_map(self::decodeTrigger(...), $document['triggerQueue'] ?? []),
            isset($document['pendingChoice']) ? self::decodeChoice($document['pendingChoice']) : null,
            $document['vars'] ?? [],
            array_map(self::decodeEvent(...), $document['log'] ?? []),
            isset($document['result']) ? self::decodeResult($document['result']) : null,
            $document['priority'] ?? null,
            (int) ($document['consecutivePasses'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    private static function encodePlayer(PlayerState $player): array
    {
        $out = ['seat' => $player->seat, 'resources' => $player->resources];
        if ($player->flags !== []) {
            $out['flags'] = $player->flags;
        }
        if ($player->identityInstance !== null) {
            $out['identityInstance'] = $player->identityInstance;
        }
        if ($player->status !== 'playing') {
            $out['status'] = $player->status;
        }

        return $out;
    }

    /**
     * @param  array<string, Instance>  $instances
     * @return array<string, array<string, mixed>>
     */
    private static function encodeInstances(array $instances): array
    {
        $out = [];
        foreach ($instances as $id => $instance) {
            $encoded = [
                'code' => $instance->code,
                'owner' => $instance->owner,
                'controller' => $instance->controller,
                'zone' => $instance->zone,
                'enteredOnRound' => $instance->enteredOnRound,
            ];
            if ($instance->face !== 'front') {
                $encoded['face'] = $instance->face;
            }
            if ($instance->exhausted) {
                $encoded['exhausted'] = true;
            }
            if ($instance->faceDown) {
                $encoded['faceDown'] = true;
            }
            if ($instance->counters !== []) {
                $encoded['counters'] = $instance->counters;
            }
            if ($instance->attachedTo !== null) {
                $encoded['attachedTo'] = $instance->attachedTo;
            }
            if ($instance->attachments !== []) {
                $encoded['attachments'] = $instance->attachments;
            }
            if ($instance->revealedTo !== []) {
                $encoded['revealedTo'] = $instance->revealedTo;
            }
            if ($instance->usedLimits !== []) {
                $encoded['usedLimits'] = $instance->usedLimits;
            }
            $out[$id] = $encoded;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function encodeModifier(ModifierRecord $modifier): array
    {
        $out = [
            'id' => $modifier->id,
            'source' => $modifier->source,
            'layer' => $modifier->layer,
            'timestamp' => $modifier->timestamp,
            'changes' => $modifier->changes,
            'duration' => $modifier->duration,
        ];
        if ($modifier->targets !== null) {
            $out['targets'] = $modifier->targets;
        }
        if ($modifier->query !== null) {
            $out['query'] = $modifier->query;
        }
        if ($modifier->abilityId !== null) {
            $out['abilityId'] = $modifier->abilityId;
        }

        return $out;
    }

    /** @param array<string, mixed> $raw */
    private static function decodeModifier(array $raw): ModifierRecord
    {
        return new ModifierRecord(
            (string) $raw['id'],
            (string) $raw['source'],
            (int) $raw['layer'],
            (int) $raw['timestamp'],
            $raw['changes'],
            $raw['targets'] ?? null,
            $raw['query'] ?? null,
            (string) ($raw['duration'] ?? ModifierRecord::DURATION_PERMANENT),
            $raw['abilityId'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private static function encodeStackItem(StackItem $item): array
    {
        $out = [
            'id' => $item->id,
            'kind' => $item->kind,
            'controller' => $item->controller,
            'frames' => array_map(self::encodeFrame(...), $item->frames),
        ];
        if ($item->bindings !== []) {
            $out['bindings'] = $item->bindings;
        }
        if ($item->sourceInstance !== null) {
            $out['sourceInstance'] = $item->sourceInstance;
        }
        if ($item->abilityId !== null) {
            $out['abilityId'] = $item->abilityId;
        }
        if ($item->depth !== 0) {
            $out['depth'] = $item->depth;
        }
        if ($item->awaiting !== null) {
            $out['awaiting'] = $item->awaiting;
        }

        return $out;
    }

    /** @param array<string, mixed> $raw */
    private static function decodeStackItem(array $raw): StackItem
    {
        return new StackItem(
            (string) $raw['id'],
            (string) $raw['kind'],
            (string) $raw['controller'],
            array_map(self::decodeFrame(...), $raw['frames']),
            $raw['bindings'] ?? [],
            $raw['sourceInstance'] ?? null,
            $raw['abilityId'] ?? null,
            (int) ($raw['depth'] ?? 0),
            $raw['awaiting'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private static function encodeFrame(StackFrame $frame): array
    {
        $out = ['program' => $frame->program->ref, 'pc' => $frame->pc];
        if ($frame->path !== []) {
            $out['path'] = $frame->path;
        }
        if ($frame->items !== null) {
            $out['items'] = $frame->items;
            $out['index'] = $frame->index;
        }
        if ($frame->vars !== []) {
            $out['vars'] = $frame->vars;
        }

        return $out;
    }

    /** @param array<string, mixed> $raw */
    private static function decodeFrame(array $raw): StackFrame
    {
        return new StackFrame(
            new ProgramRef((string) $raw['program']),
            $raw['path'] ?? [],
            (int) $raw['pc'],
            $raw['items'] ?? null,
            (int) ($raw['index'] ?? 0),
            $raw['vars'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    private static function encodeTrigger(TriggerRecord $trigger): array
    {
        $out = [
            'id' => $trigger->id,
            'event' => $trigger->event,
            'controller' => $trigger->controller,
            'program' => $trigger->program->ref,
            'queuedAt' => $trigger->queuedAt,
        ];
        if ($trigger->bindings !== []) {
            $out['bindings'] = $trigger->bindings;
        }
        if ($trigger->sourceInstance !== null) {
            $out['sourceInstance'] = $trigger->sourceInstance;
        }
        if ($trigger->abilityId !== null) {
            $out['abilityId'] = $trigger->abilityId;
        }
        if ($trigger->depth !== 0) {
            $out['depth'] = $trigger->depth;
        }

        return $out;
    }

    /** @param array<string, mixed> $raw */
    private static function decodeTrigger(array $raw): TriggerRecord
    {
        return new TriggerRecord(
            (string) $raw['id'],
            (string) $raw['event'],
            (string) $raw['controller'],
            new ProgramRef((string) $raw['program']),
            $raw['bindings'] ?? [],
            $raw['sourceInstance'] ?? null,
            $raw['abilityId'] ?? null,
            (int) ($raw['depth'] ?? 0),
            (int) ($raw['queuedAt'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    private static function encodeChoice(PendingChoice $choice): array
    {
        $out = [
            'id' => $choice->id,
            'key' => $choice->key(),
            'kind' => $choice->kind,
            'seat' => $choice->seat(),
        ];
        if ($choice->options !== []) {
            $out['options'] = $choice->options;
        }
        if ($choice->prompt !== '') {
            $out['prompt'] = $choice->prompt;
        }
        if ($choice->count !== null) {
            $out['count'] = $choice->count;
        }
        if ($choice->optional) {
            $out['optional'] = true;
        }
        if ($choice->sourceInstance !== null || $choice->abilityId !== null) {
            $out['source'] = array_filter(
                ['instance' => $choice->sourceInstance, 'ability' => $choice->abilityId],
                static fn (?string $v): bool => $v !== null,
            );
        }

        return $out;
    }

    /** @param array<string, mixed> $raw */
    private static function decodeChoice(array $raw): PendingChoice
    {
        /** @var array<string, string> $source */
        $source = $raw['source'] ?? [];

        return new PendingChoice(
            (string) ($raw['id'] ?? 'choice'),
            (string) $raw['kind'],
            Side::player((int) $raw['seat']),
            $raw['options'] ?? [],
            (string) ($raw['prompt'] ?? ''),
            $raw['count'] ?? 1,
            (bool) ($raw['optional'] ?? false),
            $source['instance'] ?? null,
            $source['ability'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private static function encodeEvent(EventRecord $event): array
    {
        $out = ['seq' => $event->seq, 'type' => $event->type];
        if ($event->payload !== []) {
            $out['payload'] = $event->payload;
        }
        foreach (['source' => $event->source, 'round' => $event->round, 'phase' => $event->phase, 'step' => $event->step] as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $raw */
    private static function decodeEvent(array $raw): EventRecord
    {
        return new EventRecord(
            (int) $raw['seq'],
            (string) $raw['type'],
            $raw['payload'] ?? [],
            $raw['source'] ?? null,
            $raw['round'] ?? null,
            $raw['phase'] ?? null,
            $raw['step'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private static function encodeResult(MatchResult $result): array
    {
        $out = [
            'reason' => $result->reason,
            'rounds' => $result->rounds,
            'winners' => $result->winners,
            'losers' => $result->losers,
        ];
        if ($result->draw) {
            $out['draw'] = true;
        }
        // The schema's scalar `winner` is a convenience for the common two-player case; the
        // authoritative answer is `winners`, because a co-op table wins or loses together.
        $out['winner'] = count($result->winners) === 1
            ? Side::seatOf($result->winners[0])
            : null;

        return $out;
    }

    /** @param array<string, mixed> $raw */
    private static function decodeResult(array $raw): MatchResult
    {
        $winners = $raw['winners'] ?? [];
        if ($winners === [] && isset($raw['winner']) && $raw['winner'] !== null) {
            $winners = [Side::player((int) $raw['winner'])];
        }

        return new MatchResult(
            $winners,
            $raw['losers'] ?? [],
            (string) ($raw['reason'] ?? ''),
            (int) ($raw['rounds'] ?? 0),
            (bool) ($raw['draw'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function pruneEmpty(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => $v !== [] && $v !== null);
    }
}
