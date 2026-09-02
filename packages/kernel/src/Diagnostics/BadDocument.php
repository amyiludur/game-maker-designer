<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** A document is malformed. */
final class BadDocument extends KernelException
{
    /** @param array<string, mixed> $context */
    public static function because(string $message, array $context = []): self
    {
        return new self(self::make(DiagnosticCode::BadDocument, $message, $context));
    }

    public static function from(Diagnostic $diagnostic): self
    {
        return new self($diagnostic);
    }
}
