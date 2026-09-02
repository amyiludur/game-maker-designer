<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect;

use Gmd\Kernel\Diagnostics\UnknownOp;

/**
 * What an op takes.
 *
 * Declared in PHP, next to the implementation, rather than in a second JSON Schema that
 * would drift from it within a month. One declaration validates authored documents, drives
 * the linter's "this op is missing a required parameter", and — once the ability builder
 * exists — supplies the op catalogue the UI builds its form from.
 */
final class OpParamSpec
{
    /** @var array<string, array{kind: string, required: bool, help: ?string}> */
    private array $params = [];

    public static function make(): self
    {
        return new self;
    }

    public function required(string $name, string $kind = 'expression', ?string $help = null): self
    {
        $this->params[$name] = ['kind' => $kind, 'required' => true, 'help' => $help];

        return $this;
    }

    public function optional(string $name, string $kind = 'expression', ?string $help = null): self
    {
        $this->params[$name] = ['kind' => $kind, 'required' => false, 'help' => $help];

        return $this;
    }

    /** @return array<string, array{kind: string, required: bool, help: ?string}> */
    public function all(): array
    {
        return $this->params;
    }

    /** @param array<string, mixed> $node */
    public function validate(string $op, array $node): void
    {
        foreach ($this->params as $name => $spec) {
            if ($spec['required'] && ! array_key_exists($name, $node)) {
                throw UnknownOp::because(
                    "op \"{$op}\" is missing required parameter \"{$name}\"",
                    ['op' => $op, 'parameter' => $name],
                );
            }
        }
    }
}
