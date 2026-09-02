<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** State checks kept firing past the iteration cap. */
final class StateCheckLoop extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::StateCheckLoop, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
