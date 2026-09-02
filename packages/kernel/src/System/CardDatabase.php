<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\Diagnostics\BadDocument;

/** Every card definition a match can draw on, keyed by code. */
final readonly class CardDatabase
{
    /** @param array<string, CardDefinition> $cards */
    public function __construct(public array $cards = []) {}

    public function get(string $code): CardDefinition
    {
        return $this->cards[$code]
            ?? throw BadDocument::because("no card definition for code \"{$code}\"");
    }

    public function has(string $code): bool
    {
        return isset($this->cards[$code]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        $codes = array_keys($this->cards);
        sort($codes);

        return $codes;
    }

    public function count(): int
    {
        return count($this->cards);
    }
}
