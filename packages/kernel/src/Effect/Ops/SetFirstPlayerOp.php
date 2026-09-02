<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Decide who goes first.
 *
 * `random` draws from the seeded stream, which is why "who went first" is reproducible from
 * a match's seed rather than being unexplained variance in a simulation batch. `alternate`
 * and `rotate` are the same operation — pass the token to the next seat — which is what
 * lets a two-player game's round structure work unchanged at a four-player table.
 */
final class SetFirstPlayerOp implements Op
{
    public function id(): string
    {
        return 'set_first_player';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->optional('rule', 'string', 'random, alternate, rotate, or fixed')
            ->optional('player', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        $draft = $context->draft;
        $seats = array_map(static fn ($player): int => $player->seat, $draft->players());
        if ($seats === []) {
            return;
        }

        $seat = match ((string) ($node['rule'] ?? 'fixed')) {
            'random' => $draft->rng()->pick($seats),
            'alternate', 'rotate' => $this->next($seats, $draft->firstSide()),
            default => isset($node['player'])
                ? $context->seatOf($context->side($node['player']))
                : $seats[0],
        };

        $draft->setFirstSeat($seat);
        $draft->setActiveSeat($seat);
        $context->emit('first_player.set', ['player' => Side::player($seat)]);
    }

    /** @param list<int> $seats */
    private function next(array $seats, string $currentSide): int
    {
        $current = Side::seatOf($currentSide);
        $at = $current === null ? false : array_search($current, $seats, true);

        return $at === false ? $seats[0] : $seats[((int) $at + 1) % count($seats)];
    }
}
