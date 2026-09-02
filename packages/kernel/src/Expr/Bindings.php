<?php

declare(strict_types=1);

namespace Gmd\Kernel\Expr;

/**
 * What the selectors in an expression currently point at.
 *
 * `$self` is this card, `$you` its controller, `$target.victim` the card that was chosen,
 * `$each` the current item of a loop. Bindings are plain serialisable values — instance
 * ids, side ids, numbers — never objects, because they travel inside a suspended stack item
 * and have to survive a round trip through Redis.
 */
final readonly class Bindings
{
    /** @param array<string, mixed> $values keyed without the leading $ */
    public function __construct(private array $values = []) {}

    public function with(string $name, mixed $value): self
    {
        return new self([...$this->values, ltrim($name, '$') => $value]);
    }

    /** @param array<string, mixed> $values */
    public function withAll(array $values): self
    {
        $merged = $this->values;
        foreach ($values as $name => $value) {
            $merged[ltrim($name, '$')] = $value;
        }

        return new self($merged);
    }

    public function has(string $name): bool
    {
        return array_key_exists(ltrim($name, '$'), $this->values);
    }

    public function get(string $name): mixed
    {
        return $this->values[ltrim($name, '$')] ?? null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * The longest bound prefix of a dotted selector, and whatever is left over.
     *
     * `$you.identity` binds `you` and leaves `identity` for the resolver to interpret;
     * `$target.victim` is bound whole. Longest-match matters because both forms exist.
     *
     * @return array{0: mixed, 1: list<string>}|null
     */
    public function resolvePrefix(string $selector): ?array
    {
        $parts = explode('.', ltrim($selector, '$'));
        for ($take = count($parts); $take >= 1; $take--) {
            $name = implode('.', array_slice($parts, 0, $take));
            if (array_key_exists($name, $this->values)) {
                return [$this->values[$name], array_slice($parts, $take)];
            }
        }

        return null;
    }
}
