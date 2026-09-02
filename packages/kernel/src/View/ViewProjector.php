<?php

declare(strict_types=1);

namespace Gmd\Kernel\View;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\Expr\EvalContext;
use Gmd\Kernel\Expr\Runtime;
use Gmd\Kernel\State\EventRecord;
use Gmd\Kernel\State\GameState;
use Gmd\Kernel\State\Instance;
use Gmd\Kernel\System\SystemDocument;
use Gmd\Kernel\System\ZoneDefinition;

/**
 * Redaction: turning a position into what one side may see.
 *
 * Zone visibility decides. A card nobody may see becomes `{id, hidden: true}` — the
 * instance id survives so the client can animate a face-down card moving between zones
 * without ever learning what it is, and deck *size* stays public while deck contents do
 * not.
 *
 * Visible cards arrive with their characteristics already computed, and with the breakdown
 * behind each modified value. The client then needs no modifier engine to render "Attack 4"
 * with an explanation, and a bot reading a view cannot cheat by recomputing from data it
 * was not given.
 */
final class ViewProjector
{
    public function __construct(private readonly Runtime $runtime) {}

    public function view(GameState $state, SystemDocument $system, string $side): PlayerView
    {
        $context = new EvalContext($state, $system, $this->runtime, new Bindings(['you' => $side]));
        $board = $this->runtime->modifiers->board($context);

        $zones = [];
        foreach ($state->zones as $zoneKey => $instanceIds) {
            [$zoneSide, $zoneId] = Side::splitZoneKey($zoneKey);
            if (! $system->hasZone($zoneId)) {
                continue;
            }
            $zone = $system->zone($zoneId);

            $cards = [];
            foreach ($instanceIds as $instanceId) {
                $instance = $state->instance($instanceId);
                $cards[] = $this->canSee($zone, $instance, $side, $zoneSide)
                    ? $this->reveal($instance, $board[$instanceId] ?? null, $context, $system)
                    : ['id' => $instanceId, 'hidden' => true];
            }

            $zones[$zoneKey] = $cards;
        }

        return new PlayerView(
            side: $side,
            viewVersion: $state->version,
            round: $state->round,
            phase: $state->phase,
            step: $state->step,
            activeSide: $state->activeSide(),
            zones: $zones,
            players: array_map($this->player(...), $state->players),
            adversaries: array_map(
                static fn ($adversary): array => ['anchors' => $adversary->anchors, 'flags' => $adversary->flags],
                $state->adversaries,
            ),
            // A choice is only ever sent to the side that has to make it.
            pendingChoice: $state->pendingChoice?->side === $side ? $state->pendingChoice : null,
            result: $state->result,
            log: array_map(
                static fn (EventRecord $e): array => ['seq' => $e->seq, 'type' => $e->type, 'payload' => $e->payload],
                $state->log,
            ),
        );
    }

    private function canSee(ZoneDefinition $zone, Instance $instance, string $side, string $zoneSide): bool
    {
        // An explicit reveal beats the zone's default: a card revealed from a hidden deck
        // stays visible to whoever saw it.
        if (in_array($side, $instance->revealedTo, true)) {
            return true;
        }

        return match ($zone->visibility) {
            ZoneDefinition::VISIBILITY_PUBLIC => true,
            ZoneDefinition::VISIBILITY_OWNER => $instance->owner === $side,
            ZoneDefinition::VISIBILITY_CONTROLLER => $instance->controller === $side,
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private function reveal(
        Instance $instance,
        ?\Gmd\Kernel\Modifier\CharacteristicSet $characteristics,
        EvalContext $context,
        SystemDocument $system,
    ): array {
        $card = [
            'id' => $instance->id,
            'code' => $instance->code,
            'name' => $characteristics?->name ?? $system->cards->get($instance->code)->name($instance->face),
            'owner' => $instance->owner,
            'controller' => $instance->controller,
            'face' => $instance->face,
        ];

        if ($instance->exhausted) {
            $card['exhausted'] = true;
        }
        if ($instance->counters !== []) {
            $card['counters'] = $instance->counters;
        }
        if ($instance->attachedTo !== null) {
            $card['attachedTo'] = $instance->attachedTo;
        }
        if ($instance->attachments !== []) {
            $card['attachments'] = $instance->attachments;
        }

        if ($characteristics !== null) {
            $card['types'] = $characteristics->types;
            $card['traits'] = $characteristics->traits;
            $card['keywords'] = $characteristics->keywords;
            $card['attributes'] = $characteristics->attributes;
            $card['modified'] = $this->modifiedAttributes($instance, $characteristics, $context);
        }

        return $card;
    }

    /**
     * Which attributes are not showing their printed value, and why.
     *
     * This is what the card inspector renders as "Attack 3 = 2 base +1 from Warhorn", and
     * it costs almost nothing because the layer walk already knows.
     *
     * @return array<string, array<string, mixed>>
     */
    private function modifiedAttributes(
        Instance $instance,
        \Gmd\Kernel\Modifier\CharacteristicSet $characteristics,
        EvalContext $context,
    ): array {
        $modified = [];
        foreach ($characteristics->attributes as $attribute => $current) {
            // Ask the cheap question first. Comparing against the printed value is two array
            // lookups; building a breakdown walks every modifier on the board and resolves
            // their queries, and on a typical board none of the values have moved.
            if ($current === $this->runtime->modifiers->baseAttribute($context, $instance->id, (string) $attribute)) {
                continue;
            }

            $breakdown = $this->runtime->modifiers->breakdown($context, $instance->id, (string) $attribute);
            if (! $breakdown->isModified()) {
                continue;
            }
            $modified[(string) $attribute] = [
                'printed' => $breakdown->printed,
                'current' => $breakdown->current,
                'from' => array_map(
                    static fn (array $step): array => [
                        'source' => $step['sourceName'],
                        'mode' => $step['mode'],
                        'amount' => $step['amount'],
                        'layer' => $step['layer'],
                    ],
                    $breakdown->steps,
                ),
            ];
        }

        return $modified;
    }

    /** @return array<string, mixed> */
    private function player(\Gmd\Kernel\State\PlayerState $player): array
    {
        return array_filter([
            'seat' => $player->seat,
            'side' => $player->side(),
            'resources' => $player->resources,
            'identityInstance' => $player->identityInstance,
            'status' => $player->status,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
