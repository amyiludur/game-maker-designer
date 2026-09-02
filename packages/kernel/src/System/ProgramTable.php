<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\Diagnostics\BadDocument;
use Gmd\Kernel\State\ProgramRef;

/**
 * Every effect script in the game, addressable by a stable reference.
 *
 * The interpreter's stack points at programs rather than carrying copies of them. That is
 * what keeps a suspended stack small enough to sit in Redis, in a state hash and in a
 * replay — and it is why an effect interrupted by a player's choice can be resumed by a
 * different process than the one that started it.
 */
final readonly class ProgramTable
{
    /** @param array<string, list<mixed>> $programs ref => the op list at its root */
    public function __construct(private array $programs = []) {}

    /** @return list<mixed> */
    public function root(ProgramRef|string $ref): array
    {
        $key = (string) $ref;

        return $this->programs[$key]
            ?? throw BadDocument::because("no compiled program \"{$key}\"");
    }

    public function has(ProgramRef|string $ref): bool
    {
        return isset($this->programs[(string) $ref]);
    }

    /**
     * The op list a stack frame is executing, reached by walking its path from the root.
     *
     * @param  list<string>  $path
     * @return list<mixed>
     */
    public function opsAt(ProgramRef|string $ref, array $path = []): array
    {
        $node = $this->root($ref);
        foreach ($path as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                throw BadDocument::because(
                    'program "' . $ref . '" has no node at path /' . implode('/', $path),
                );
            }
            $node = $node[$key];
        }

        if (! is_array($node)) {
            throw BadDocument::because('program "' . $ref . '" path /' . implode('/', $path) . ' is not an op list');
        }

        return array_values($node);
    }

    /** @return list<string> */
    public function refs(): array
    {
        $refs = array_keys($this->programs);
        sort($refs);

        return $refs;
    }

    public function count(): int
    {
        return count($this->programs);
    }
}
