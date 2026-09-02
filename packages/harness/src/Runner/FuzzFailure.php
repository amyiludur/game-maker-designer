<?php

declare(strict_types=1);

namespace Gmd\Harness\Runner;

use Gmd\Kernel\Diagnostics\Diagnostic;
use Gmd\Kernel\State\GameState;

/** A seed that broke something, kept whole so it can become a regression fixture. */
final readonly class FuzzFailure
{
    public function __construct(
        public int $seed,
        public string $message,
        public ?GameState $state = null,
        public ?Diagnostic $diagnostic = null,
    ) {}

    public function describe(): string
    {
        return "seed {$this->seed}: {$this->message}";
    }
}
