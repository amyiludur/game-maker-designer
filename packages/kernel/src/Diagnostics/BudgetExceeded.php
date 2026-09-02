<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** A kernel budget was exhausted. */
final class BudgetExceeded extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::BudgetExceeded, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
