<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** The effect or expression names an op the kernel does not implement. */
final class UnknownOp extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::UnknownOp, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
