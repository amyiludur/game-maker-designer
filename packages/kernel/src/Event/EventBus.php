<?php

declare(strict_types=1);

namespace Gmd\Kernel\Event;

use Gmd\Kernel\Budgets;
use Gmd\Kernel\Diagnostics\TriggerDepthExceeded;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\State\EventRecord;
use Gmd\Kernel\State\TriggerRecord;
use Gmd\Kernel\System\CompiledAbility;

/**
 * Events, in two phases: offered, then announced.
 *
 * `propose()` runs the replacement window — anything that would change or prevent the event
 * gets its say while the event has not happened yet. The op then performs the change and
 * calls `announce()`, which logs it and collects the triggers that watch for it.
 *
 * Two phases rather than one because a single emit() forces every caller to answer "has
 * this happened yet?", and that question is precisely where replacement-effect bugs live.
 * With two, the answer is structural: before propose it has not, after announce it has.
 */
final class EventBus
{
    public function propose(GameEvent $event, OpContext $context): ?GameEvent
    {
        $candidates = $this->applicable($event, $context, replacements: true);
        if ($candidates === []) {
            return $event;
        }

        foreach ($candidates as [$ability, $instanceId, $bindings]) {
            $key = $instanceId . '#' . $ability->id;
            if ($event->wasReplacedBy($key)) {
                // A replacement applies once per event, or two of them could bounce an
                // event between each other forever.
                continue;
            }
            $event = $this->applyReplacement($event->replacedBy($key), $ability, $instanceId, $bindings, $context);
            if ($event->prevented) {
                return null;
            }
        }

        return $event;
    }

    public function announce(GameEvent $event, OpContext $context): void
    {
        $draft = $context->draft;

        $draft->emit(new EventRecord(
            $draft->nextEventSeq(),
            $event->type,
            $event->payload,
            $event->source,
            $draft->round(),
            $draft->phase(),
            $draft->step(),
        ));

        $depth = $context->item->depth + 1;
        if ($depth > Budgets::TRIGGER_DEPTH) {
            throw TriggerDepthExceeded::because(
                'triggered abilities nested past ' . Budgets::TRIGGER_DEPTH . ' levels',
                ['event' => $event->type],
            );
        }

        foreach ($this->applicable($event, $context, replacements: false) as [$ability, $instanceId, $bindings]) {
            $draft->queueTrigger(new TriggerRecord(
                id: $draft->nextId('trigger', 't'),
                event: $event->type,
                controller: $draft->instance($instanceId)->controller,
                program: $ability->effectProgram(),
                bindings: $bindings->all(),
                sourceInstance: $instanceId,
                abilityId: $ability->id,
                depth: $depth,
                queuedAt: $draft->tick(),
            ));
        }
    }

    /**
     * Which abilities on the board are watching for this event.
     *
     * A triggered ability is live wherever its card is, unless `activeWhile` says
     * otherwise. That is not laxity: Smother is an event card that has already been put in
     * the discard by the time its own "when played" trigger fires, and requiring cards to
     * be in play would silently break it. Cards that should only listen from play say so
     * with activeWhile.
     *
     * @return list<array{0: CompiledAbility, 1: string, 2: Bindings}>
     */
    private function applicable(GameEvent $event, OpContext $context, bool $replacements): array
    {
        $abilities = $context->system->abilitiesTriggeredBy($event->type);
        if ($abilities === []) {
            return [];
        }

        $found = [];
        foreach ($context->draft->instances() as $instanceId => $instance) {
            foreach ($abilities as $ability) {
                if ($ability->ownerCode !== $instance->code || $ability->face !== $instance->face) {
                    continue;
                }
                if ($ability->isReplacement() !== $replacements) {
                    continue;
                }

                $bindings = new Bindings([
                    'self' => $instanceId,
                    'you' => $instance->controller,
                    'param' => $ability->params,
                    ...$event->bindings(),
                ]);
                $evaluation = $context->eval();
                $scoped = new \Gmd\Kernel\Expr\EvalContext(
                    $evaluation->state,
                    $evaluation->system,
                    $evaluation->runtime,
                    $bindings,
                );

                if ($ability->activeWhile !== null && ! $scoped->evaluateBool($ability->activeWhile)) {
                    continue;
                }
                $filter = $ability->trigger['filter'] ?? null;
                if ($filter !== null && ! $scoped->evaluateBool($filter)) {
                    continue;
                }

                $found[] = [$ability, $instanceId, $bindings];
            }
        }

        return $found;
    }

    /**
     * Run a replacement ability's effect and read back what it changed.
     *
     * Replacements use `modify_event` to alter a field of the event in flight, which is why
     * they are the one kind of ability that resolves inline rather than going on the stack:
     * the event they are changing has not happened yet, and the stack would resolve too
     * late to matter.
     */
    private function applyReplacement(
        GameEvent $event,
        CompiledAbility $ability,
        string $instanceId,
        Bindings $bindings,
        OpContext $context,
    ): GameEvent {
        $program = $ability->effectProgram();
        if (! $context->system->programs->has($program)) {
            return $event;
        }

        foreach ($context->system->programs->root($program) as $node) {
            if (! is_array($node)) {
                continue;
            }
            $evaluation = new \Gmd\Kernel\Expr\EvalContext(
                $context->draft,
                $context->system,
                $context->runtime,
                $bindings->withAll($event->bindings()),
            );

            if (($node['op'] ?? null) === 'modify_event') {
                $event = $event->withField(
                    (string) $node['field'],
                    $evaluation->evaluate($node['value'] ?? null),
                );
            } elseif (($node['op'] ?? null) === 'prevent_event') {
                $event = $event->prevented();
            }
        }

        return $event;
    }
}
