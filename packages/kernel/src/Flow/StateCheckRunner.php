<?php

declare(strict_types=1);

namespace Gmd\Kernel\Flow;

use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\System\StateCheckDefinition;
use Gmd\Kernel\System\SystemDocument;

/**
 * The rules the engine enforces continuously — lethal damage, hand size, uniqueness.
 *
 * MtG calls these state-based actions. The generalisation here is that the engine knows
 * none of them: a game declares that a card with damage at or above its health is
 * destroyed, and that is the only reason damage is lethal.
 *
 * Subjects are collected first and only then acted on. That is what makes simultaneous
 * strike kill both creatures: evaluating and destroying one at a time would let the first
 * death change the board before the second card was examined.
 */
final class StateCheckRunner
{
    /** @return bool whether anything fired */
    public function run(Draft $draft, SystemDocument $system, OpContext $context): bool
    {
        $fired = false;

        foreach ($system->stateChecks as $check) {
            if (! $check->appliesIn($draft->phase(), $draft->step())) {
                continue;
            }

            foreach ($this->firingSubjects($check, $draft, $system, $context) as [$binding, $subject]) {
                $draft->pushStack(new StackItem(
                    id: $draft->nextId('stack', 's'),
                    kind: StackItem::KIND_STATE_CHECK,
                    controller: $this->controllerFor($binding, $subject, $draft),
                    frames: [new StackFrame($check->thenProgram())],
                    bindings: [$binding => $subject, 'you' => $this->controllerFor($binding, $subject, $draft)],
                ));
                $fired = true;
            }

            if ($fired) {
                // One check at a time: its response may change the board enough that the
                // next check's subjects are different, and the settle loop will come back.
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: string, 1: string}> pairs of (binding name, subject id)
     */
    private function firingSubjects(
        StateCheckDefinition $check,
        Draft $draft,
        SystemDocument $system,
        OpContext $context,
    ): array {
        $evaluation = $context->pure();

        if ($check->scopesPlayers()) {
            $subjects = [];
            foreach ($draft->players() as $player) {
                if (! $player->isPlaying()) {
                    continue;
                }
                $scoped = $evaluation->bindAll(['player' => $player->side(), 'you' => $player->side()]);
                if ($scoped->evaluateBool($check->when)) {
                    $subjects[] = ['player', $player->side()];
                }
            }

            return $subjects;
        }

        $candidates = $system->cards->count() === 0
            ? []
            : $context->runtime->queries->cards($this->cardScope($check), $evaluation);

        $subjects = [];
        foreach ($candidates as $instanceId) {
            $scoped = $evaluation->bindAll([
                'card' => $instanceId,
                'you' => $draft->instance($instanceId)->controller,
            ]);
            if ($scoped->evaluateBool($check->when)) {
                $subjects[] = ['card', $instanceId];
            }
        }

        return $subjects;
    }

    /** @return array<string, mixed> */
    private function cardScope(StateCheckDefinition $check): array
    {
        $scope = $check->scope;
        unset($scope['players']);

        return $scope;
    }

    private function controllerFor(string $binding, string $subject, Draft $draft): string
    {
        return $binding === 'player'
            ? $subject
            : $draft->instance($subject)->controller;
    }
}
