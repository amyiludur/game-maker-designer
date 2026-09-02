<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** A selector could not be resolved in the current bindings. */
final class UnresolvedSelector extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::UnresolvedSelector, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
