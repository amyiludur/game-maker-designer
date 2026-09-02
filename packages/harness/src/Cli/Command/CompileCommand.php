<?php

declare(strict_types=1);

namespace Gmd\Harness\Cli\Command;

use Gmd\Harness\Cli\Arguments;
use Gmd\Harness\Cli\Command;
use Gmd\Kernel\Effect\OpRegistry;
use Gmd\Kernel\System\StepDefinition;

/** Compile a game and say what came out. Useful for checking a system document loads at all. */
final class CompileCommand extends Command
{
    public function run(Arguments $arguments): int
    {
        $game = $this->game($arguments);
        $system = $game->system;
        $ops = OpRegistry::standard();

        $this->line();
        $this->line("  {$system->name} v{$system->version}  ({$system->id})");
        $this->line("  {$system->digest}");
        $this->line();
        $this->line(sprintf('  %-14s %d-%d, %s', 'players', $system->minPlayers(), $system->maxPlayers(), $system->mode()));
        $this->line(sprintf('  %-14s %s', 'zones', implode(', ', array_keys($system->zones))));
        $this->line(sprintf('  %-14s %s', 'card types', implode(', ', array_keys($system->cardTypes))));
        $this->line(sprintf('  %-14s %s', 'keywords', implode(', ', array_keys($system->keywords)) ?: '—'));
        $this->line(sprintf('  %-14s %s', 'actions', implode(', ', array_keys($system->actions))));
        $this->line(sprintf('  %-14s %d', 'cards', $system->cards->count()));
        $this->line(sprintf('  %-14s %d', 'programs', $system->programs->count()));
        $this->line();

        $this->line('  round');
        foreach ($system->steps() as $step) {
            /** @var StepDefinition $step */
            $this->line(sprintf(
                '    %-28s %s',
                $step->qualifiedId(),
                $step->hasAuto ? 'auto' : 'window · ' . ($step->window?->type ?? '?'),
            ));
        }
        $this->line();

        $unknown = $this->unknownOps($game->system, $ops);
        if ($unknown !== []) {
            $this->line('  ops this kernel does not implement:');
            foreach ($unknown as $op => $where) {
                $this->line(sprintf('    %-22s %s', $op, implode(', ', array_slice($where, 0, 3))));
            }
            $this->line();

            return 1;
        }

        $this->line('  every op used is implemented');
        $this->line();

        return 0;
    }

    /**
     * @return array<string, list<string>> op => the programs that use it
     */
    private function unknownOps(\Gmd\Kernel\System\SystemDocument $system, OpRegistry $ops): array
    {
        $unknown = [];
        foreach ($system->programs->refs() as $ref) {
            foreach ($this->opsIn($system->programs->root($ref)) as $op) {
                if (! $ops->has($op)) {
                    $unknown[$op][] = $ref;
                }
            }
        }

        return $unknown;
    }

    /**
     * @param  mixed  $node
     * @return list<string>
     */
    private function opsIn(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        // Only effect ops are collected: an expression node's `op` lives under keys like
        // `cond` or `value`, and the two vocabularies are separate.
        if (isset($node['op']) && is_string($node['op'])) {
            $found[] = $node['op'];
        }
        foreach (['do', 'then', 'else', 'effect'] as $key) {
            foreach ($node[$key] ?? [] as $child) {
                $found = [...$found, ...$this->opsIn($child)];
            }
        }
        foreach ($node as $key => $child) {
            if (is_int($key) && is_array($child)) {
                $found = [...$found, ...$this->opsIn($child)];
            }
        }

        return array_values(array_unique($found));
    }
}
