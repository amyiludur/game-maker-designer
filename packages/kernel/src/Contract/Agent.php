<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

use Gmd\Kernel\View\PlayerView;

/**
 * Something that can play.
 *
 * Agents are handed a PlayerView, not a GameState — the same redacted object a human gets.
 * A bot therefore cannot cheat by construction, which is the only reason bot-derived
 * balance statistics mean anything (doc 09).
 */
interface Agent
{
    public function id(): string;

    public function chooseAction(PlayerView $view, ActionList $legal): Action;

    public function resolveChoice(PlayerView $view, PendingChoice $choice): ChoiceResponse;
}
