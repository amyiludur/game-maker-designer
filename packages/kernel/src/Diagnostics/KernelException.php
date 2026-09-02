<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/** Base class for every failure the kernel raises. Always carries a structured Diagnostic. */
abstract class KernelException extends \RuntimeException
{
    public function __construct(private readonly Diagnostic $diagnostic)
    {
        parent::__construct($diagnostic->describe());
    }

    public function diagnostic(): Diagnostic
    {
        return $this->diagnostic;
    }

    /** @param array<string, mixed> $context */
    protected static function make(DiagnosticCode $code, string $message, array $context = []): Diagnostic
    {
        return new Diagnostic($code, $message, context: $context);
    }
}
