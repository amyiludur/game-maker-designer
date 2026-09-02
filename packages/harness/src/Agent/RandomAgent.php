<?php

declare(strict_types=1);

namespace Gmd\Harness\Agent;

use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\Contract\ActionList;
use Gmd\Kernel\Contract\Agent;
use Gmd\Kernel\Contract\ChoiceResponse;
use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Rng\Rng;
use Gmd\Kernel\View\PlayerView;

/**
 * Plays uniformly at random.
 *
 * The least interesting bot and the most useful one. Random play is how the fuzz harness
 * finds crashes, infinite loops and illegal states — it reaches board positions no
 * heuristic ever would, and it does not need to be good at the game to prove the engine
 * never breaks.
 *
 * It has its own RNG stream, separate from the match's. A bot that drew from the game's
 * generator would change the shuffle by thinking, and two bots would interfere with each
 * other's decisions.
 */
final class RandomAgent implements Agent
{
    public function __construct(
        private readonly Rng $rng,
        private readonly float $passBias = 0.15,
    ) {}

    public function id(): string
    {
        return 'random';
    }

    public function chooseAction(PlayerView $view, ActionList $legal): Action
    {
        $actions = $legal->actions;
        if ($actions === []) {
            return Action::pass($view->side);
        }

        $real = array_values(array_filter($actions, static fn ($a): bool => $a->actionId !== Action::PASS));

        // Some willingness to pass, or a random bot in an alternating window would keep
        // acting until it ran out of resources and rounds would never end.
        if ($real === [] || $this->rng->nextInt(0, 99) < (int) ($this->passBias * 100)) {
            return Action::pass($view->side);
        }

        return $this->rng->pick($real)->toAction();
    }

    public function resolveChoice(PlayerView $view, PendingChoice $choice): ChoiceResponse
    {
        return match ($choice->kind) {
            PendingChoice::CHOOSE_NUMBER => new ChoiceResponse(
                $choice->id,
                number: $this->rng->nextInt(
                    (int) ($choice->options['min'] ?? 0),
                    (int) ($choice->options['max'] ?? 0),
                ),
            ),
            PendingChoice::YES_NO => new ChoiceResponse($choice->id, yes: $this->rng->nextInt(0, 1) === 1),
            PendingChoice::CHOOSE_PLAYERS => new ChoiceResponse(
                $choice->id,
                $this->take($choice, (array) ($choice->options['players'] ?? [])),
            ),
            PendingChoice::ORDER_ITEMS => new ChoiceResponse(
                $choice->id,
                $this->rng->shuffle(array_values((array) ($choice->options['items'] ?? []))),
            ),
            default => new ChoiceResponse($choice->id, $this->take($choice, (array) ($choice->options['cards'] ?? []))),
        };
    }

    /**
     * @param  list<mixed>  $candidates
     * @return list<mixed>
     */
    private function take(PendingChoice $choice, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $wanted = is_int($choice->count) ? $choice->count : 1;
        if ($choice->optional && $this->rng->nextInt(0, 3) === 0) {
            return [];
        }

        return array_slice($this->rng->shuffle(array_values($candidates)), 0, min($wanted, count($candidates)));
    }
}
