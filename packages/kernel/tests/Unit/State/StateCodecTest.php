<?php

declare(strict_types=1);

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\State\Codec\CanonicalJson;
use Gmd\Kernel\State\Codec\StateCodec;
use Gmd\Kernel\State\Codec\StateHasher;
use Gmd\Kernel\State\EventRecord;
use Gmd\Kernel\State\GameState;
use Gmd\Kernel\State\Instance;
use Gmd\Kernel\State\MatchResult;
use Gmd\Kernel\State\ModifierRecord;
use Gmd\Kernel\State\PlayerState;
use Gmd\Kernel\State\ProgramRef;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\State\TriggerRecord;
use Gmd\Kernel\Tests\Support\Schemas;

function sampleState(): GameState
{
    return new GameState(
        systemId: 'emberfall',
        systemVersion: '0.4.0',
        systemDigest: 'sha256:abc',
        seed: 8412773901,
        rngPosition: 12,
        version: 7,
        round: 2,
        phase: 'action',
        step: 'main',
        activeSeat: 0,
        firstSeat: 1,
        players: [
            new PlayerState(0, ['ember' => 3], identityInstance: 'i-p0-1'),
            new PlayerState(1, ['ember' => 2], identityInstance: 'i-p1-1', status: 'playing'),
        ],
        zones: [
            'p0.hand' => ['i-p0-4', 'i-p0-9'],
            'p0.play' => ['i-p0-1', 'i-p0-2'],
            'p1.hand' => [],
            'shared.removed' => [],
        ],
        instances: [
            'i-p0-1' => new Instance('i-p0-1', 'core-001', 'p0', 'p0', 'p0.play', counters: ['damage' => 4]),
            'i-p0-2' => new Instance('i-p0-2', 'core-010', 'p0', 'p0', 'p0.play', exhausted: true, enteredOnRound: 1),
            'i-p0-4' => new Instance('i-p0-4', 'core-016', 'p0', 'p0', 'p0.hand'),
            'i-p0-9' => new Instance('i-p0-9', 'core-012', 'p0', 'p0', 'p0.hand'),
        ],
        modifiers: [
            new ModifierRecord(
                'm1',
                'i-p0-4',
                6,
                3,
                [['attr' => 'attack', 'mode' => 'add', 'value' => 2]],
                targets: ['i-p0-2'],
                duration: ModifierRecord::DURATION_WHILE_SOURCE_IN_PLAY,
                abilityId: 'a1',
            ),
        ],
        stack: [
            new StackItem('s1', StackItem::KIND_ABILITY, 'p0', [
                new StackFrame(ProgramRef::card('core-012', 'a1'), pc: 1),
            ], sourceInstance: 'i-p0-9', abilityId: 'a1'),
        ],
        triggerQueue: [
            new TriggerRecord('t1', 'card.entered_zone', 'p0', ProgramRef::card('core-023', 'a1'), queuedAt: 1),
        ],
        pendingChoice: new PendingChoice('victim', PendingChoice::CHOOSE_CARDS, Side::player(1), ['cards' => ['i-p0-2']], 'Choose a character', sourceInstance: 'i-p1-3', abilityId: 'a1'),
        vars: ['__ts' => 3, '__eventSeq' => 11, 'combat.attacks' => [], '__enteredAt' => ['i-p0-2' => 2]],
        log: [new EventRecord(11, 'card.played', ['card' => 'i-p0-2', 'player' => 'p0'], round: 2)],
        priority: 0,
        consecutivePasses: 1,
    );
}

it('encodes to a document the published schema accepts', function (): void {
    expect(Schemas::violations(StateCodec::encode(sampleState()), 'game-state'))->toBe([]);
});

it('round-trips byte-identically', function (): void {
    $once = StateCodec::encode(sampleState());
    $twice = StateCodec::encode(StateCodec::decode($once, 'emberfall', 'sha256:abc'));

    expect(CanonicalJson::encode($twice))->toBe(CanonicalJson::encode($once));
});

it('omits fields that sit at their default', function (): void {
    $encoded = StateCodec::encode(sampleState());

    // Exactly one way to say "not exhausted", or two equal states could hash differently.
    expect($encoded['instances']['i-p0-1'])->not->toHaveKey('exhausted');
    expect($encoded['instances']['i-p0-2']['exhausted'])->toBeTrue();
    expect($encoded['instances']['i-p0-4'])->not->toHaveKey('counters');
});

it('hashes equal positions equally regardless of map insertion order', function (): void {
    $state = sampleState();
    $reordered = $state->with([
        'instances' => array_reverse($state->instances, preserve_keys: true),
    ]);

    expect(StateHasher::hash($reordered))->toBe(StateHasher::hash($state));
});

it('excludes the event log from the hash', function (): void {
    $state = sampleState();
    $withMoreLog = $state->with([
        'log' => [...$state->log, new EventRecord(12, 'card.exhausted', ['card' => 'i-p0-2'])],
    ]);

    // The log is a capped presentation buffer. If it were hashed, a conformance replay would
    // fail purely because a different amount of history had been retained.
    expect(StateHasher::hash($withMoreLog))->toBe(StateHasher::hash($state));
});

it('includes the system digest in the hash', function (): void {
    $state = sampleState();

    // The same board under different rules is not the same position.
    expect(StateHasher::hash($state->with(['systemDigest' => 'sha256:different'])))
        ->not->toBe(StateHasher::hash($state));
});

it('includes the engine counters in the hash', function (): void {
    $state = sampleState();
    $ticked = $state->with(['vars' => [...$state->vars, '__ts' => 99]]);

    // The timestamp counter orders every future modifier, so two states differing in it
    // will diverge later. They are different positions and must hash differently.
    expect(StateHasher::hash($ticked))->not->toBe(StateHasher::hash($state));
});

it('reports a co-op outcome as a table rather than a single winner', function (): void {
    $state = sampleState()->with([
        'result' => new MatchResult([], ['p0', 'p1'], 'scheme_completed', 6),
    ]);
    $encoded = StateCodec::encode($state);

    expect($encoded['result']['losers'])->toBe(['p0', 'p1']);
    expect($encoded['result']['winner'])->toBeNull();
    expect(Schemas::violations($encoded, 'game-state'))->toBe([]);
});
