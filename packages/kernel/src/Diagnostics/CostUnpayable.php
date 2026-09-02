<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** A cost could not be paid. */
final class CostUnpayable extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::CostUnpayable, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
