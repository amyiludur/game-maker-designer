<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Run a body once per player, binding `$player`.
 *
 * `order: "turn"` starts from the current first player and goes round the table, which is
 * what makes a villain's activation script written for two players behave correctly at
 * four (doc 16 §8). `order: "seat"` is fixed seat order, for setup.
 */
final class ForEachPlayerOp implements Op
{
    public function id(): string
    {
        return 'for_each_player';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('do', 'effect')
            ->optional('order', 'string', '"turn" (from the first player) or "seat"');
    }

    public function execute(array $node, OpContext $context): void
    {
        $sides = $this->order($node, $context);
        if ($sides === []) {
            return;
        }

        $context->descend(
            $context->childPath('do'),
            ['__loopVar' => 'player', 'player' => $sides[0]],
            $sides,
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function order(array $node, OpContext $context): array
    {
        $players = array_values(array_filter(
            $context->draft->players(),
            static fn ($player): bool => $player->isPlaying(),
        ));
        $sides = array_map(static fn ($player): string => $player->side(), $players);

        if (($node['order'] ?? 'turn') !== 'turn') {
            return $sides;
        }

        $first = $context->draft->firstSide();
        $at = array_search($first, $sides, true);

        return $at === false
            ? $sides
            : [...array_slice($sides, (int) $at), ...array_slice($sides, 0, (int) $at)];
    }
}
