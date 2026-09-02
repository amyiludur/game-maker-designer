<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

/**
 * A stable, serialisable pointer to an effect script in the compiled system.
 *
 * Stack items reference programs; they never inline them. That keeps a suspended stack
 * small enough to sit in Redis and in a state hash, and it means a mid-effect state parked
 * by one worker can be resumed by another — which is the whole reason the interpreter uses
 * an explicit program counter rather than PHP generators (a generator cannot be
 * serialised, and doc 08's runtime moves live states between processes).
 *
 * Examples: `card:core-024#a1.effect`, `action:play_event.effect`, `system:setup`,
 * `adversary:warden.activation`, `keyword:bolster.effect`.
 */
final readonly class ProgramRef implements \Stringable
{
    public function __construct(public string $ref) {}

    public static function card(string $code, string $abilityId, string $part = 'effect'): self
    {
        return new self("card:{$code}#{$abilityId}.{$part}");
    }

    public static function action(string $actionId, string $part = 'effect'): self
    {
        return new self("action:{$actionId}.{$part}");
    }

    public static function system(string $part): self
    {
        return new self("system:{$part}");
    }

    public static function stateCheck(string $id): self
    {
        return new self("statecheck:{$id}.then");
    }

    public static function adversary(string $adversaryId): self
    {
        return new self("adversary:{$adversaryId}.activation");
    }

    public function __toString(): string
    {
        return $this->ref;
    }
}
