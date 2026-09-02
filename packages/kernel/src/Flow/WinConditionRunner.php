<?php

declare(strict_types=1);

namespace Gmd\Kernel\Flow;

use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\MatchResult;
use Gmd\Kernel\System\SystemDocument;
use Gmd\Kernel\System\WinConditionDefinition;

/**
 * How the game ends.
 *
 * The outcome vocabulary covers both traditions in one format (doc 16 §6): `winner` and
 * `loser` for a duel, `allWin` and `allLose` for a cooperative table, and `eliminate` for
 * the games where a defeated player is out and the others play on. Swapping one field turns
 * Marvel Champions into Arkham Horror.
 */
final class WinConditionRunner
{
    public function evaluate(Draft $draft, SystemDocument $system, OpContext $context, ?string $justHappened = null): ?MatchResult
    {
        $evaluation = $context->pure();

        foreach ($system->winConditions as $condition) {
            // A condition gated on an event only gets asked in response to that event —
            // "loses when they must draw from an empty deck" is not the same as "loses
            // whenever their deck is empty".
            if ($condition->isEventGated() && $condition->trigger !== $justHappened) {
                continue;
            }

            if ($condition->scopesPlayers()) {
                foreach ($draft->players() as $player) {
                    if (! $player->isPlaying()) {
                        continue;
                    }
                    $scoped = $evaluation->bindAll(['player' => $player->side(), 'you' => $player->side()]);
                    if ($scoped->evaluateBool($condition->check)) {
                        $result = $this->outcome($condition, $player->side(), $draft);
                        if ($result !== null) {
                            return $result;
                        }
                    }
                }

                continue;
            }

            if ($evaluation->evaluateBool($condition->check)) {
                $result = $this->outcome($condition, null, $draft);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Turn a fired condition into a result, or eliminate one player and let play continue.
     */
    private function outcome(WinConditionDefinition $condition, ?string $subject, Draft $draft): ?MatchResult
    {
        $outcome = $condition->outcome;
        $everyone = $draft->playerSides();
        $stillPlaying = array_values(array_filter(
            $everyone,
            static fn (string $side): bool => $draft->playerBySide($side)->isPlaying(),
        ));

        if (($outcome['draw'] ?? false) === true) {
            return new MatchResult([], [], $condition->id, $draft->round(), draw: true);
        }

        if (($outcome['allWin'] ?? false) === true) {
            return new MatchResult($stillPlaying, [], $condition->id, $draft->round());
        }

        if (($outcome['allLose'] ?? false) === true) {
            return new MatchResult([], $stillPlaying, $condition->id, $draft->round());
        }

        if (isset($outcome['eliminate'])) {
            $eliminated = $this->resolve($outcome['eliminate'], $subject);
            if ($eliminated === null) {
                return null;
            }
            $player = $draft->playerBySide($eliminated);
            $draft->setPlayer($player->with(['status' => 'eliminated']));

            $remaining = array_values(array_diff($stillPlaying, [$eliminated]));

            // The table plays on unless one seat is left, which is then the winner.
            return count($remaining) <= 1
                ? new MatchResult($remaining, array_values(array_diff($everyone, $remaining)), $condition->id, $draft->round())
                : null;
        }

        if (isset($outcome['loser'])) {
            $loser = $this->resolve($outcome['loser'], $subject);
            if ($loser === null) {
                return null;
            }

            return new MatchResult(
                array_values(array_diff($stillPlaying, [$loser])),
                [$loser],
                $condition->id,
                $draft->round(),
            );
        }

        if (isset($outcome['winner'])) {
            $winner = $this->resolve($outcome['winner'], $subject);
            if ($winner === null) {
                return null;
            }

            return new MatchResult(
                [$winner],
                array_values(array_diff($stillPlaying, [$winner])),
                $condition->id,
                $draft->round(),
            );
        }

        return null;
    }

    private function resolve(mixed $value, ?string $subject): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return str_starts_with($value, '$') ? $subject : $value;
    }
}
