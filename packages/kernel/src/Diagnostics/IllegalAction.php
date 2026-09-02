<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** The action is not legal in the current state. */
final class IllegalAction extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::IllegalAction, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
