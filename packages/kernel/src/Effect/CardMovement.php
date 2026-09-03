<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\State\ModifierRecord;

/**
 * Moving a card between zones, with everything that entails.
 *
 * Several ops move cards — play, draw, discard, destroy, return to hand — and the fiddly
 * part is the same every time and easy to get subtly wrong in one of them. Leaving play in
 * particular has to shed the things that only exist while a card is on the table: its
 * damage, its exhausted state, its attachments, and any lingering modifier that pointed at
 * it. A single copy of that is one place to be right.
 */
final class CardMovement
{
    /**
     * @param  ?string  $side  whose copy of the zone; defaults to the card's own controller
     * @param  ?string  $controller  who controls the card afterwards; defaults to $side
     * @param  'top'|'bottom'|int  $position
     */
    public static function move(
        OpContext $context,
        string $instanceId,
        string $toZoneId,
        ?string $side = null,
        string|int $position = 'bottom',
        ?bool $faceDown = null,
        ?string $face = null,
        ?string $controller = null,
    ): void {
        $draft = $context->draft;
        $instance = $draft->instance($instanceId);

        $fromKey = $instance->zone;
        [, $fromZoneId] = Side::splitZoneKey($fromKey);

        [$qualified, $toZoneId] = self::splitDestination($context, $toZoneId);

        $toSide = $qualified ?? $side ?? $instance->controller;
        $toController = $controller ?? $toSide;
        $toKey = $context->zoneKey($toSide, $toZoneId);
        $toZone = $context->system->zone($toZoneId);

        $leavingPlay = self::isPlayZone($context, $fromZoneId) && ! self::isPlayZone($context, $toZoneId);

        $context->emit('card.left_zone', [
            'card' => $instanceId,
            'from' => $fromZoneId,
            'to' => $toZoneId,
            'controller' => $instance->controller,
        ], $instanceId);

        $draft->removeFromZone($fromKey, $instanceId);

        $changes = [
            'zone' => $toKey,
            'controller' => $toController,
            'faceDown' => $faceDown ?? $toZone->faceDown,
            'enteredOnRound' => $draft->round(),
        ];
        if ($face !== null) {
            $changes['face'] = $face;
        }

        if ($leavingPlay) {
            // A card that leaves play is a new object when it comes back: no damage, ready,
            // and carrying nothing.
            $changes['counters'] = [];
            $changes['exhausted'] = false;
            $changes['attachedTo'] = null;
            $changes['attachments'] = [];
            self::detachFrom($context, $instanceId);
            self::dropAttachments($context, $instanceId);
            $draft->removeModifiers(
                static fn (ModifierRecord $m): bool => $m->source === $instanceId
                    || ($m->targets !== null && in_array($instanceId, $m->targets, true)),
            );
        }

        $draft->mutateInstance($instanceId, $changes);
        $draft->insertIntoZone($toKey, $instanceId, $position);
        $draft->recordEntry($instanceId);

        $context->emit('card.entered_zone', [
            'card' => $instanceId,
            'from' => $fromZoneId,
            'to' => $toZoneId,
            'controller' => $toController,
            'position' => is_int($position) ? $position : $position,
        ], $instanceId);
    }

    /**
     * Split a destination into the side holding the zone and the zone id itself.
     *
     * A destination may name the zone alone — `discard`, meaning the moving card's own — or
     * qualify it with the side that holds it, as `warden.encounter_discard` does. The
     * qualified form is what lets a player's card send a defeated minion back to the
     * villain's pile without knowing anything about the villain.
     *
     * @return array{0: ?string, 1: string} the side, or null for "the card's own", and the zone id
     */
    public static function splitDestination(OpContext $context, string $destination): array
    {
        if (! str_contains($destination, '.')) {
            return [null, $destination];
        }

        [$side, $zoneId] = Side::splitZoneKey($destination);

        // An unqualified zone whose id happens to contain a dot is not a thing this format
        // allows, but failing here would name the wrong culprit; let zone() say so instead.
        return $context->system->hasZone($zoneId) ? [$side, $zoneId] : [null, $destination];
    }

    /** Detach this card from whatever it was attached to. */
    public static function detachFrom(OpContext $context, string $instanceId): void
    {
        $instance = $context->draft->instance($instanceId);
        $hostId = $instance->attachedTo;
        if ($hostId === null || ! $context->draft->hasInstance($hostId)) {
            return;
        }

        $host = $context->draft->instance($hostId);
        $context->draft->mutateInstance($hostId, [
            'attachments' => array_values(array_diff($host->attachments, [$instanceId])),
        ]);
    }

    /** Send everything attached to this card to its owner's discard. */
    public static function dropAttachments(OpContext $context, string $hostId): void
    {
        $host = $context->draft->instance($hostId);
        foreach ($host->attachments as $attachmentId) {
            if (! $context->draft->hasInstance($attachmentId)) {
                continue;
            }
            $context->draft->mutateInstance($attachmentId, ['attachedTo' => null]);
            self::move($context, $attachmentId, self::discardZone($context), $context->draft->instance($attachmentId)->owner);
        }
    }

    public static function isPlayZone(OpContext $context, string $zoneId): bool
    {
        return $context->system->hasZone($zoneId) && $context->system->zone($zoneId)->supportsAttachments;
    }

    /**
     * The zone a destroyed or discarded card goes to.
     *
     * Taken from the card type's `playableTo` where a game says so, and otherwise the first
     * public ordered per-player zone — which is what "discard pile" means in every game
     * this platform is for, without the kernel hard-coding the word.
     */
    public static function discardZone(OpContext $context): string
    {
        foreach ($context->system->zones as $zone) {
            if ($zone->id === 'discard') {
                return $zone->id;
            }
        }
        foreach ($context->system->zones as $zone) {
            if ($zone->isPerSide() && $zone->ordered && $zone->visibility === 'public') {
                return $zone->id;
            }
        }

        throw \Gmd\Kernel\Diagnostics\BadDocument::because(
            'this game declares no discard pile, so there is nowhere for a destroyed card to go',
        );
    }
}
