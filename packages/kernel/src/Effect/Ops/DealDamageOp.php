<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;
use Gmd\Kernel\Effect\ReadsGrants;

/**
 * Deal damage, as damage counters.
 *
 * The amount goes through the replacement window first, so "prevent 1 damage" and "damage
 * dealt to your hero is doubled" are ordinary replacement abilities rather than special
 * cases in the engine.
 *
 * `defendable` opens the cooperative format's one interactive moment. The adversary's
 * activation is a script with nothing to decide in it — except this: an attack a player may
 * step in front of, exhausting a defender to take the hit instead (doc 16 §7). Which cards
 * can defend is read from a granted permission rather than a card type, so it is the
 * keyword that decides, and no game name reaches the kernel.
 */
final class DealDamageOp implements Op, ReadsGrants
{
    /** The permission a card must be granted to stand in front of an attack. */
    public const MAY_DEFEND = 'may_defend';

    public function id(): string
    {
        return 'deal_damage';
    }

    public function grantsRead(): array
    {
        return [self::MAY_DEFEND];
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('target', 'selector')
            ->required('amount', 'expression')
            ->optional('source', 'selector')
            ->optional('counter', 'string')
            ->optional('defendable', 'boolean', 'Offer the damaged side a chance to put a defender in the way.')
            ->optional('defenders', 'query', 'Which cards may defend; defaults to any the game permits to.');
    }

    public function execute(array $node, OpContext $context): void
    {
        $counter = (string) ($node['counter'] ?? 'damage');
        $targets = $context->cardList($node['target']);
        $defendable = ($node['defendable'] ?? false) === true;

        // Every defence is settled before any damage lands. An awaited choice re-runs this
        // op from the top when it is answered, so a target damaged before the await would be
        // damaged twice; asking first and applying afterwards makes the op re-entrant.
        if ($defendable && $this->awaitDefences($context, $node, $targets)) {
            return;
        }

        foreach ($targets as $target) {
            $victim = $defendable ? $this->interpose($context, $node, $target) : $target;

            $event = $context->propose('damage.dealt', [
                'target' => $victim,
                'amount' => $context->evaluateInt($node['amount']),
                'source' => isset($node['source']) ? $context->card($node['source']) : $context->item->sourceInstance,
            ]);

            if ($event === null) {
                $context->emit('damage.prevented', ['target' => $victim], $context->item->sourceInstance);

                continue;
            }

            $amount = max(0, (int) $event->get('amount', 0));
            if ($amount === 0) {
                continue;
            }

            $instance = $context->draft->instance($victim);
            $context->draft->mutateInstance($victim, [
                'counters' => [...$instance->counters, $counter => $instance->counter($counter) + $amount],
            ]);
            $context->emit('damage.dealt', $event->payload, $event->source);
        }
    }

    /**
     * Ask each threatened side whether it wants to defend, one at a time.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $targets
     * @return bool whether the op parked on a question and must be re-run
     */
    private function awaitDefences(OpContext $context, array $node, array $targets): bool
    {
        foreach ($targets as $target) {
            $candidates = $this->defenders($context, $node, $target);
            if ($candidates === [] || $context->answer($this->choiceId($target)) !== null) {
                continue;
            }

            $context->await(new PendingChoice(
                id: $this->choiceId($target),
                kind: PendingChoice::CHOOSE_CARDS,
                side: $context->draft->instance($target)->controller,
                options: ['cards' => $candidates],
                prompt: 'Choose a defender, or take the damage',
                count: 1,
                optional: true,
                sourceInstance: $context->item->sourceInstance,
                abilityId: $context->item->abilityId,
            ));

            return true;
        }

        return false;
    }

    /**
     * Put the chosen defender in the way, exhausting it.
     *
     * The choice is re-checked rather than trusted: it was made before the first target's
     * damage resolved, and an earlier hit in the same attack may have killed the defender or
     * exhausted it.
     *
     * @param  array<string, mixed>  $node
     */
    private function interpose(OpContext $context, array $node, string $target): string
    {
        $answer = $context->answer($this->choiceId($target));
        $chosen = is_array($answer) ? ($answer[0] ?? null) : $answer;

        if (! is_string($chosen) || ! in_array($chosen, $this->defenders($context, $node, $target), true)) {
            return $target;
        }

        $context->draft->mutateInstance($chosen, ['exhausted' => true]);
        $context->emit('damage.defended', [
            'defender' => $chosen,
            'protecting' => $target,
        ], $context->item->sourceInstance);

        return $chosen;
    }

    /**
     * Who may stand in the way of a hit on this card.
     *
     * The default is any ready card on the table controlled by the same side that the game
     * has granted the defending permission to. A game that wants a narrower rule says so
     * with a `defenders` query and gets it evaluated instead.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function defenders(OpContext $context, array $node, string $target): array
    {
        if (isset($node['defenders'])) {
            return array_values(array_diff($context->cards($node['defenders']), [$target]));
        }

        $side = $context->draft->instance($target)->controller;
        if (! Side::isPlayer($side)) {
            return [];
        }

        $eval = $context->pure();
        $defenders = [];

        foreach ($context->draft->instances() as $id => $instance) {
            if ($id === $target || $instance->controller !== $side || $instance->exhausted) {
                continue;
            }
            [, $zoneId] = Side::splitZoneKey($instance->zone);
            if (! CardMovement::isPlayZone($context, $zoneId)) {
                continue;
            }
            if ($context->runtime->modifiers->characteristics($eval, $id)->permits(self::MAY_DEFEND)) {
                $defenders[] = $id;
            }
        }

        return $defenders;
    }

    /** Keyed per target so a multi-target attack asks one question per victim. */
    private function choiceId(string $target): string
    {
        return 'defender.' . $target;
    }
}
