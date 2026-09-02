<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** Triggered abilities nested past the depth cap. */
final class TriggerDepthExceeded extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::TriggerDepthExceeded, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
