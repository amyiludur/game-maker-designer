<?php

declare(strict_types=1);

namespace Gmd\Harness\Agent;

use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\Contract\ActionList;
use Gmd\Kernel\Contract\Agent;
use Gmd\Kernel\Contract\ChoiceResponse;
use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\View\PlayerView;

/**
 * Replays a fixed sequence, and fails loudly the moment reality diverges.
 *
 * This is what a golden replay is driven by. Failing loudly is the point: if the recorded
 * line is no longer legal, the engine's behaviour has changed, and that is exactly what a
 * conformance fixture exists to notice.
 */
final class ScriptedAgent implements Agent
{
    private int $cursor = 0;

    /**
     * @param  list<array{actionId: string, params?: array<string, mixed>, choice?: array<string, mixed>}>  $script
     */
    public function __construct(
        private readonly array $script,
        private readonly string $side,
    ) {}

    public function id(): string
    {
        return 'scripted';
    }

    public function chooseAction(PlayerView $view, ActionList $legal): Action
    {
        $entry = $this->script[$this->cursor] ?? null;
        if ($entry === null) {
            throw new \RuntimeException("scripted agent for {$this->side} ran out of script");
        }

        $action = new Action($entry['actionId'], $view->side, $entry['params'] ?? []);
        if ($legal->find($action) === null) {
            throw new \RuntimeException(sprintf(
                'scripted action %d (%s %s) is not legal for %s in %s.%s; on offer: %s',
                $this->cursor + 1,
                $entry['actionId'],
                json_encode($entry['params'] ?? []),
                $view->side,
                $view->phase,
                $view->step,
                implode(', ', $legal->actionIds()),
            ));
        }

        $this->cursor++;

        return $action;
    }

    public function resolveChoice(PlayerView $view, PendingChoice $choice): ChoiceResponse
    {
        // A choice belongs to the action that raised it, which is the one just consumed.
        $entry = $this->script[$this->cursor - 1] ?? [];
        $answers = $entry['choice'] ?? [];

        $selection = $answers[$choice->key()] ?? $answers[$choice->id] ?? null;
        if ($selection === null) {
            throw new \RuntimeException(sprintf(
                'scripted agent for %s has no answer for choice "%s" (%s)',
                $this->side,
                $choice->key(),
                $choice->prompt,
            ));
        }

        return new ChoiceResponse($choice->id, is_array($selection) ? array_values($selection) : [$selection]);
    }

    public function position(): int
    {
        return $this->cursor;
    }
}
