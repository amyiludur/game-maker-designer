<?php

declare(strict_types=1);

namespace Gmd\Harness\Agent;

use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\Contract\ActionList;
use Gmd\Kernel\Contract\Agent;
use Gmd\Kernel\Contract\ChoiceResponse;
use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\View\PlayerView;

/**
 * Wraps another agent and writes down everything it was asked.
 *
 * A golden replay has to be derived from a match that actually happened rather than typed
 * out, or the fixture records what someone believed the engine does. The transcript this
 * builds is the replay document's `actions` array, choices included — and choices are the
 * half that cannot be reconstructed afterwards, because the answer to "choose a defender"
 * is not recoverable from the resulting state.
 *
 * Every seat shares one transcript. Actions interleave across seats in the order the match
 * took them, which is the order a replay must feed them back.
 */
final class RecordingAgent implements Agent
{
    /**
     * @param  list<array{seq: int, seat: int, actionId: string, params?: array<string, mixed>, choice?: array<string, mixed>}>  $transcript
     */
    public function __construct(
        private readonly Agent $inner,
        private array &$transcript,
    ) {}

    public function id(): string
    {
        return $this->inner->id();
    }

    public function chooseAction(PlayerView $view, ActionList $legal): Action
    {
        $action = $this->inner->chooseAction($view, $legal);

        $entry = [
            'seq' => count($this->transcript) + 1,
            'seat' => Side::seatOrFail($view->side),
            'actionId' => $action->actionId,
        ];
        if ($action->params !== []) {
            $entry['params'] = $action->params;
        }
        $this->transcript[] = $entry;

        return $action;
    }

    /**
     * Record the answer against the action that provoked it.
     *
     * A choice always arises out of settling the last action taken — the villain's
     * activation runs in that settle too — which is exactly where the replay runner looks
     * for the answer.
     */
    public function resolveChoice(PlayerView $view, PendingChoice $choice): ChoiceResponse
    {
        $response = $this->inner->resolveChoice($view, $choice);

        $at = count($this->transcript) - 1;
        if ($at >= 0) {
            $answers = $this->transcript[$at]['choice'] ?? [];
            $answers[$choice->key()] = match ($choice->kind) {
                PendingChoice::CHOOSE_NUMBER => $response->number,
                PendingChoice::YES_NO => $response->yes,
                default => $response->selection,
            };
            $this->transcript[$at]['choice'] = $answers;
        }

        return $response;
    }
}
