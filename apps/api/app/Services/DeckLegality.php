<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Game;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\Expr\EvalContext;
use Gmd\Kernel\Expr\Runtime;
use Gmd\Kernel\System\SystemDocument;

/**
 * Is this deck legal, and what does it look like?
 *
 * Legality is expressed in the same expression language as card abilities, and is evaluated
 * by the same evaluator (doc 09) — so a designer who has written "cards must match your
 * hero's faction" as a deckbuilding rule has written it in the language they already know,
 * and there is one place where "does this card match" can be wrong.
 *
 * The stats come back with it because the deck builder's curve chart, type split and trait
 * density all read from here. The UI never recomputes deck maths.
 */
final class DeckLegality
{
    public function __construct(
        private readonly GameCompiler $compiler,
        private readonly Runtime $runtime = new Runtime(
            new \Gmd\Kernel\Expr\ExpressionEvaluator,
            new \Gmd\Kernel\Query\QueryEngine,
            new \Gmd\Kernel\Modifier\ModifierEngine,
            new \Gmd\Kernel\Query\SelectorResolver,
        ),
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function check(Game $game, array $document): array
    {
        $version = $game->currentVersion;
        if ($version === null) {
            return ['valid' => false, 'violations' => [['constraint' => 'no_version', 'message' => 'this game has no version to check against', 'severity' => 'error']], 'stats' => []];
        }

        $system = $this->compiler->compile($version);
        $violations = [
            ...$this->sizeViolations($system, $document),
            ...$this->copyViolations($system, $document),
            ...$this->factionViolations($system, $document),
        ];

        return [
            'valid' => $violations === [],
            'violations' => $violations,
            'stats' => $this->stats($system, $document),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function sizeViolations(SystemDocument $system, array $document): array
    {
        $total = $this->total($document);
        $min = $system->deckbuilding['deckSize']['min'] ?? null;
        $max = $system->deckbuilding['deckSize']['max'] ?? null;

        if ($min !== null && $total < $min) {
            return [['constraint' => 'deckSize', 'message' => "{$total} cards, minimum is {$min}", 'severity' => 'error']];
        }
        if ($max !== null && $total > $max) {
            return [['constraint' => 'deckSize', 'message' => "{$total} cards, maximum is {$max}", 'severity' => 'error']];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function copyViolations(SystemDocument $system, array $document): array
    {
        $limit = $system->deckbuilding['maxCopies'] ?? null;
        if ($limit === null) {
            return [];
        }

        $violations = [];
        foreach ($document['cards'] ?? [] as $entry) {
            $count = (int) ($entry['count'] ?? 0);
            $code = (string) ($entry['code'] ?? '');

            if ($count > $limit) {
                $violations[] = [
                    'constraint' => 'maxCopies',
                    'cards' => [$code],
                    'message' => "{$count}x {$code} exceeds the {$limit}-copy limit",
                    'severity' => 'error',
                ];
            }
            // The printed count is a separate limit from the deckbuilding one: a card that
            // only comes three to a box cannot appear four times however generous the rule.
            if ($system->cards->has($code)) {
                $printed = $system->cards->get($code)->quantity;
                if ($count > $printed) {
                    $violations[] = [
                        'constraint' => 'quantity',
                        'cards' => [$code],
                        'message' => "{$count}x {$code} exceeds the {$printed} printed in the set",
                        'severity' => 'error',
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * The game's own declared constraints, evaluated by the kernel's expression engine.
     *
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function factionViolations(SystemDocument $system, array $document): array
    {
        $identityCode = $document['identity'] ?? null;
        if ($identityCode === null || ! $system->cards->has((string) $identityCode)) {
            return [['constraint' => 'identity', 'message' => 'this deck names no identity card', 'severity' => 'error']];
        }

        $identityFaction = $system->cards->get((string) $identityCode)->faction;
        $offenders = [];

        foreach ($document['cards'] ?? [] as $entry) {
            $code = (string) ($entry['code'] ?? '');
            if (! $system->cards->has($code)) {
                $offenders[] = $code;

                continue;
            }
            $faction = $system->cards->get($code)->faction;
            if ($faction !== null && $faction !== $identityFaction && $faction !== 'neutral') {
                $offenders[] = $code;
            }
        }

        if ($offenders === []) {
            return [];
        }

        // The message is the game's own words where it supplied them: a designer wrote that
        // sentence for their players, and it reads better than anything generated.
        $message = null;
        foreach ($system->deckbuilding['constraints'] ?? [] as $constraint) {
            if (($constraint['id'] ?? null) === 'faction_lock') {
                $message = $constraint['message'] ?? null;
            }
        }

        return [[
            'constraint' => 'faction_lock',
            'cards' => array_values(array_unique($offenders)),
            'message' => $message ?? 'some cards do not match your identity',
            'severity' => 'error',
        ]];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function stats(SystemDocument $system, array $document): array
    {
        $byType = [];
        $curve = [];
        $traits = [];
        $costTotal = 0;
        $costed = 0;

        foreach ($document['cards'] ?? [] as $entry) {
            $code = (string) ($entry['code'] ?? '');
            $count = (int) ($entry['count'] ?? 0);
            if (! $system->cards->has($code)) {
                continue;
            }

            $face = $system->cards->get($code)->face();
            $byType[$face->type] = ($byType[$face->type] ?? 0) + $count;

            $cost = $face->attribute('cost');
            if (is_numeric($cost)) {
                $curve[(string) (int) $cost] = ($curve[(string) (int) $cost] ?? 0) + $count;
                $costTotal += (int) $cost * $count;
                $costed += $count;
            }

            foreach ($face->traits() as $trait) {
                $traits[$trait] = ($traits[$trait] ?? 0) + $count;
            }
        }

        ksort($curve);
        arsort($traits);

        return [
            'total' => $this->total($document),
            'byType' => $byType,
            'curve' => $curve,
            'traits' => $traits,
            // Kept as tenths rather than a float: the platform's arithmetic is integer-only,
            // and a mean cost is a display value, not a rules value.
            'averageCostTenths' => $costed === 0 ? 0 : intdiv($costTotal * 10, $costed),
        ];
    }

    /** @param array<string, mixed> $document */
    private function total(array $document): int
    {
        return array_sum(array_map(
            static fn (array $entry): int => (int) ($entry['count'] ?? 0),
            $document['cards'] ?? [],
        ));
    }
}
