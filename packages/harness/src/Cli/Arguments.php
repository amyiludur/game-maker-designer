<?php

declare(strict_types=1);

namespace Gmd\Harness\Cli;

/** Positional arguments and --options, parsed once. */
final class Arguments
{
    /** @var list<string> */
    private array $positional = [];

    /** @var array<string, string|bool> */
    private array $options = [];

    /** @param list<string> $argv */
    public function __construct(array $argv)
    {
        foreach ($argv as $argument) {
            if (! str_starts_with($argument, '--')) {
                $this->positional[] = $argument;

                continue;
            }
            $body = substr($argument, 2);
            $at = strpos($body, '=');
            if ($at === false) {
                $this->options[$body] = true;
            } else {
                $this->options[substr($body, 0, $at)] = substr($body, $at + 1);
            }
        }
    }

    public function at(int $index, ?string $default = null): ?string
    {
        return $this->positional[$index] ?? $default;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function integer(string $name, int $default): int
    {
        $value = $this->option($name);

        return $value === null ? $default : (int) $value;
    }

    public function flag(string $name): bool
    {
        return ($this->options[$name] ?? false) !== false;
    }
}
