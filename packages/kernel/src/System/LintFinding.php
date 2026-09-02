<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/** Something the linter noticed, and how much it matters. */
final readonly class LintFinding implements \JsonSerializable
{
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const INFO = 'info';

    public function __construct(
        public string $severity,
        public string $rule,
        public string $message,
        public ?string $where = null,
        public ?string $fix = null,
    ) {}

    public static function error(string $rule, string $message, ?string $where = null, ?string $fix = null): self
    {
        return new self(self::ERROR, $rule, $message, $where, $fix);
    }

    public static function warning(string $rule, string $message, ?string $where = null, ?string $fix = null): self
    {
        return new self(self::WARNING, $rule, $message, $where, $fix);
    }

    public static function info(string $rule, string $message, ?string $where = null, ?string $fix = null): self
    {
        return new self(self::INFO, $rule, $message, $where, $fix);
    }

    public function describe(): string
    {
        return sprintf(
            '%-7s %-26s %s%s',
            $this->severity,
            $this->rule,
            $this->message,
            $this->where === null ? '' : "  ({$this->where})",
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'severity' => $this->severity,
            'rule' => $this->rule,
            'message' => $this->message,
            'where' => $this->where,
            'fix' => $this->fix,
        ], static fn (?string $v): bool => $v !== null);
    }
}
