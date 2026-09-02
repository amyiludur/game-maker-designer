<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** A decision window opened with nothing to decide. */
final class NoLegalActions extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::NoLegalActions, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
