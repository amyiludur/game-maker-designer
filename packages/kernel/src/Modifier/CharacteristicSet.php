<?php

declare(strict_types=1);

namespace Gmd\Kernel\Modifier;

use Gmd\Kernel\System\CompiledAbility;

/**
 * What a card actually is right now, after every continuous effect has had its say.
 *
 * None of this is stored in the game state (ADR-0004). It is recomputed from the printed
 * card plus the active modifiers on every read, which is why a buff that expires cannot
 * leave a stale number behind and why "why is this a 4/3?" always has an answer.
 */
final readonly class CharacteristicSet
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $types
     * @param  list<string>  $traits
     * @param  list<string>  $keywords
     * @param  array<string, bool>  $permissions
     * @param  array<string, bool>  $restrictions
     * @param  list<CompiledAbility>  $abilities
     */
    public function __construct(
        public string $instanceId,
        public string $name,
        public array $attributes,
        public array $types,
        public array $traits,
        public array $keywords,
        public array $permissions,
        public array $restrictions,
        public array $abilities,
        public string $controller,
    ) {}

    public function attribute(string $id): mixed
    {
        return $this->attributes[$id] ?? null;
    }

    public function integer(string $id): int
    {
        $value = $this->attributes[$id] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }

    public function isType(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function hasTrait(string $trait): bool
    {
        return in_array($trait, $this->traits, true);
    }

    public function hasKeyword(string $keyword): bool
    {
        return in_array($keyword, $this->keywords, true);
    }

    public function permits(string $permission): bool
    {
        return ($this->permissions[$permission] ?? false) === true;
    }

    public function restricted(string $restriction): bool
    {
        return ($this->restrictions[$restriction] ?? false) === true;
    }

    /** A stable fingerprint, used to decide whether the fixed-point walk has settled. */
    public function fingerprint(): string
    {
        return serialize([
            $this->attributes,
            $this->types,
            $this->traits,
            $this->keywords,
            $this->permissions,
            $this->restrictions,
            $this->controller,
        ]);
    }
}
