<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** The game system document could not be compiled. */
final class CompileError extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::CompileError, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
