<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;
use Gmd\Kernel\State\ModifierRecord;

/**
 * Create a continuous effect.
 *
 * The `target` / `query` choice is the significant one, and it is preserved into the state
 * rather than collapsed: a modifier created with `target` resolves its cards now and keeps
 * them (Bolster's "+1 to another friendly character this round" picks one and is done),
 * while one created with `query` re-evaluates every time it is read (an aura has to notice
 * cards that arrive later).
 *
 * Static abilities do not come through here at all — they are derived from the board on
 * every read, so that they stop applying the instant their source leaves play.
 */
final class ModifyOp implements Op
{
    public function id(): string
    {
        return 'modify';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('changes', 'array')
            ->optional('target', 'selector')
            ->optional('query', 'query')
            ->optional('duration', 'string')
            ->optional('layer', 'integer');
    }

    public function execute(array $node, OpContext $context): void
    {
        $changes = $node['changes'] ?? [];
        if ($changes === []) {
            return;
        }

        $targets = isset($node['target']) ? $context->cardList($node['target']) : null;
        if ($targets === []) {
            // A modifier with a target that resolved to nothing is not a modifier.
            return;
        }

        $context->draft->addModifier(new ModifierRecord(
            id: $context->draft->nextId('modifier', 'm'),
            source: $context->item->sourceInstance ?? 'system',
            layer: (int) ($node['layer'] ?? $this->layerFor($changes)),
            timestamp: $context->draft->tick(),
            changes: $this->resolveValues($changes, $context),
            targets: $targets,
            query: $node['query'] ?? null,
            duration: (string) ($node['duration'] ?? ModifierRecord::DURATION_PERMANENT),
            abilityId: $context->item->abilityId,
        ));
    }

    /**
     * Values are evaluated now, not stored as expressions.
     *
     * "+N where N is the number of Soldiers you control" means the number when the ability
     * resolved, not a number that keeps changing afterwards. Effects that should keep
     * changing are written as static abilities instead.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return list<array{attr: string, mode: string, value: mixed}>
     */
    private function resolveValues(array $changes, OpContext $context): array
    {
        return array_values(array_map(
            static fn (array $change): array => [
                'attr' => (string) $change['attr'],
                'mode' => (string) ($change['mode'] ?? 'add'),
                'value' => $context->evaluate($change['value'] ?? 0),
            ],
            $changes,
        ));
    }

    /** @param list<array<string, mixed>> $changes */
    private function layerFor(array $changes): int
    {
        $modes = array_map(static fn (array $c): string => (string) ($c['mode'] ?? 'add'), $changes);

        if (in_array('multiply', $modes, true)) {
            return 7;
        }

        return in_array('set', $modes, true) ? 5 : 6;
    }
}
