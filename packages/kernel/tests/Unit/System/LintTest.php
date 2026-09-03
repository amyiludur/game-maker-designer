<?php

declare(strict_types=1);

use Gmd\Kernel\System\Lint;
use Gmd\Kernel\System\LintFinding;
use Gmd\Kernel\System\SystemCompiler;
use Gmd\Kernel\Tests\Support\Fixtures;

/** @return list<string> */
function ruleNames(array $findings): array
{
    return array_map(static fn (LintFinding $f): string => $f->rule, $findings);
}

it('finds nothing serious in the competitive example', function (): void {
    $findings = Lint::standard()->check(Fixtures::emberfall());
    $errors = array_filter($findings, static fn (LintFinding $f): bool => $f->severity === LintFinding::ERROR);

    expect(array_map(static fn (LintFinding $f): string => $f->describe(), $errors))->toBe([]);
});

it('notices a counter nothing ever uses', function (): void {
    // Emberfall declares a `charge` counter and no card puts one on anything. Harmless, and
    // exactly the sort of leftover that accumulates in a design over months.
    expect(ruleNames(Lint::standard()->check(Fixtures::emberfall())))->toContain('unused-counter');
});

it('notices a keyword whose grant nothing reads', function (): void {
    // Emberfall's `guard` grants `must_be_attacked_first` and nothing consults it. Two
    // printed cards carry it, so it reads like a rule, a designer balances around it, and
    // it does nothing — the most expensive kind of nothing a card game can print.
    $findings = Lint::standard()->check(Fixtures::emberfall());
    $unread = array_filter($findings, static fn (LintFinding $f): bool => $f->rule === 'unread-grant');

    expect(array_map(static fn (LintFinding $f): string => $f->message, $unread))
        ->toHaveCount(1)
        ->and(implode('', array_map(static fn (LintFinding $f): string => $f->message, $unread)))
        ->toContain('must_be_attacked_first');
});

it('does not flag a grant that an action requirement reads', function (): void {
    // `swift` grants `attack_while_summoning_sick`, which nothing in any *effect* mentions —
    // it is read by `declare_attack`'s requirements. A scan that only walked compiled
    // programs called this dead, which would have taught designers to ignore the rule.
    $messages = array_map(
        static fn (LintFinding $f): string => $f->message,
        Lint::standard()->check(Fixtures::emberfall()),
    );

    expect(implode('', $messages))->not->toContain('attack_while_summoning_sick');
});

it('reports ops the kernel has not grown yet', function (): void {
    // The linter saying so at load is the whole point: the alternative is UnknownOp at
    // round 12 of match 7,431.
    $document = Fixtures::json(Fixtures::path('emberfall') . '/game-system.json');
    $document['round']['phases'][0]['steps'][0]['auto'] = [['op' => 'summon_kraken']];

    $findings = Lint::standard()->check((new SystemCompiler)->compile($document, []));
    $unknown = array_filter($findings, static fn (LintFinding $f): bool => $f->rule === 'unknown-op');

    expect(array_map(static fn (LintFinding $f): string => $f->message, $unknown))
        ->toContain('the kernel does not implement the op "summon_kraken"');
});

it('finds no unimplemented op in the cooperative example', function (): void {
    // Warden's Hollow is the conformance target for the co-op shape, and every op it reaches
    // for — reveal_encounter, run_activation — is one the kernel had to grow to play it. If
    // this fails, the example is asking for a primitive that does not exist.
    $findings = Lint::standard()->check(Fixtures::wardensHollow());
    $unknown = array_filter($findings, static fn (LintFinding $f): bool => $f->rule === 'unknown-op');

    expect(array_map(static fn (LintFinding $f): string => $f->message, $unknown))->toBe([]);
});

it('insists the game can end', function (): void {
    $document = Fixtures::json(Fixtures::path('emberfall') . '/game-system.json');
    $document['winConditions'] = [];

    $findings = Lint::standard()->check((new SystemCompiler)->compile($document, []));

    // A game with no way to end is not a design choice, it is a simulation batch that never
    // returns.
    expect(ruleNames($findings))->toContain('no-win-condition')->toContain('no-round-cap');
});

it('insists on a round cap even when the game can otherwise be won', function (): void {
    $document = Fixtures::json(Fixtures::path('emberfall') . '/game-system.json');
    $document['winConditions'] = array_values(array_filter(
        $document['winConditions'],
        static fn (array $c): bool => $c['id'] !== 'round_limit',
    ));

    expect(ruleNames(Lint::standard()->check((new SystemCompiler)->compile($document, []))))
        ->toContain('no-round-cap');
});

it('catches a selector no target declares', function (): void {
    $document = Fixtures::json(Fixtures::path('emberfall') . '/game-system.json');
    foreach ($document['actions'] as $index => $action) {
        if ($action['id'] === 'play_character') {
            $document['actions'][$index]['effect'][0]['card'] = '$target.nonexistent';
        }
    }

    $findings = Lint::standard()->check((new SystemCompiler)->compile($document, []));

    expect(ruleNames($findings))->toContain('undeclared-target');
});
