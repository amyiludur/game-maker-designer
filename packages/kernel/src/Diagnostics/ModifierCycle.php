<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** Continuous effects did not reach a fixed point within the pass budget. */
final class ModifierCycle extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::ModifierCycle, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
