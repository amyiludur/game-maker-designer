<?php

declare(strict_types=1);

namespace Gmd\Kernel\Tests\Support;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\Expr\EvalContext;
use Gmd\Kernel\Expr\Runtime;
use Gmd\Kernel\Rng\Pcg64Rng;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\GameState;
use Gmd\Kernel\State\Instance;
use Gmd\Kernel\State\PlayerState;
use Gmd\Kernel\System\SystemDocument;

/**
 * A hand-built board, for tests that need a specific position rather than a whole match.
 *
 * Most rules bugs are about a particular arrangement of cards — this one buffed, that one
 * exhausted, a third attached — and setting that up by playing a game to reach it is slow
 * to write and fragile to read. This builds the position directly.
 */
final class Board
{
    /** @var array<string, Instance> */
    private array $instances = [];

    /** @var array<string, list<string>> */
    private array $zones = [];

    /** @var array<int, PlayerState> */
    private array $players = [];

    /** @var array<string, int> */
    private array $ordinals = [];

    /** @var array<string, list<string>> code => instance ids, in placement order */
    private array $byCode = [];

    /** @var array<string, mixed> */
    private array $vars = [];

    private int $round = 1;

    private string $phase = 'action';

    private string $step = 'main';

    private int $activeSeat = 0;

    private function __construct(private readonly SystemDocument $system) {}

    public static function of(SystemDocument $system): self
    {
        return new self($system);
    }

    public static function emberfall(): self
    {
        return new self(Fixtures::emberfall());
    }

    /** @param array<string, int> $resources */
    public function seat(int $seat, ?string $identity = null, array $resources = []): self
    {
        $identityInstance = null;
        if ($identity !== null) {
            $identityInstance = $this->place($seat, $identity, 'play');
        }
        $this->players[$seat] = new PlayerState($seat, $resources, identityInstance: $identityInstance);

        return $this;
    }

    public function inPlay(int $seat, string $code, bool $exhausted = false, int $enteredOnRound = 0): self
    {
        $id = $this->place($seat, $code, 'play');
        $this->instances[$id] = $this->instances[$id]->with([
            'exhausted' => $exhausted,
            'enteredOnRound' => $enteredOnRound,
        ]);

        return $this;
    }

    public function inHand(int $seat, string $code): self
    {
        $this->place($seat, $code, 'hand');

        return $this;
    }

    public function inZone(int $seat, string $code, string $zone): self
    {
        $this->place($seat, $code, $zone);

        return $this;
    }

    /** @param array<string, int> $counters */
    public function counters(string $instanceId, array $counters): self
    {
        $this->instances[$instanceId] = $this->instances[$instanceId]->with(['counters' => $counters]);

        return $this;
    }

    /** Attach the most recently placed copy of $code to $hostId. */
    public function attach(int $seat, string $code, string $hostId): self
    {
        $id = $this->place($seat, $code, 'play');
        $this->instances[$id] = $this->instances[$id]->with(['attachedTo' => $hostId]);
        $host = $this->instances[$hostId];
        $this->instances[$hostId] = $host->with(['attachments' => [...$host->attachments, $id]]);

        return $this;
    }

    public function round(int $round): self
    {
        $this->round = $round;

        return $this;
    }

    public function at(string $phase, string $step): self
    {
        $this->phase = $phase;
        $this->step = $step;

        return $this;
    }

    public function active(int $seat): self
    {
        $this->activeSeat = $seat;

        return $this;
    }

    public function var(string $key, mixed $value): self
    {
        $this->vars[$key] = $value;

        return $this;
    }

    /** The nth instance of a card code, in placement order. */
    public function id(string $code, int $nth = 0): string
    {
        return $this->byCode[$code][$nth]
            ?? throw new \RuntimeException("no instance {$nth} of {$code} on this board");
    }

    public function build(): GameState
    {
        ksort($this->players);

        return new GameState(
            systemId: $this->system->id,
            systemVersion: $this->system->version,
            systemDigest: $this->system->digest,
            seed: 1,
            rngPosition: 0,
            version: 1,
            round: $this->round,
            phase: $this->phase,
            step: $this->step,
            activeSeat: $this->activeSeat,
            firstSeat: 0,
            players: array_values($this->players),
            zones: $this->zones,
            instances: $this->instances,
            vars: $this->vars,
        );
    }

    public function draft(): Draft
    {
        return Draft::of($this->build(), Pcg64Rng::at(1));
    }

    /** @param array<string, mixed> $bindings */
    public function context(array $bindings = [], ?GameState $state = null): EvalContext
    {
        return new EvalContext(
            $state ?? $this->build(),
            $this->system,
            Runtime::make(),
            new Bindings($bindings),
        );
    }

    public function system(): SystemDocument
    {
        return $this->system;
    }

    private function place(int $seat, string $code, string $zoneId): string
    {
        $side = Side::player($seat);
        $ordinal = ($this->ordinals[$side] ?? 0) + 1;
        $this->ordinals[$side] = $ordinal;
        $id = 'i-' . $side . '-' . $ordinal;

        $zoneKey = $this->system->qualifiedZone($side, $zoneId);
        $this->instances[$id] = new Instance($id, $code, $side, $side, $zoneKey);
        $this->zones[$zoneKey][] = $id;
        $this->byCode[$code][] = $id;

        return $id;
    }
}
