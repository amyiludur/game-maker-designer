<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

/**
 * An answer to a PendingChoice.
 *
 * Answering is a first-class kernel entry point rather than a callback passed into
 * settle(), because a callback cannot cross a process boundary: doc 08's runtime parks a
 * mid-effect state in Redis and may resume it in a different worker.
 */
final readonly class ChoiceResponse
{
    /** @param list<mixed> $selection instance ids, side ids, option ids, or an ordering */
    public function __construct(
        public string $choiceId,
        public array $selection = [],
        public ?int $number = null,
        public ?bool $yes = null,
    ) {}

    public static function cards(string $choiceId, string ...$instanceIds): self
    {
        return new self($choiceId, array_values($instanceIds));
    }

    public static function declined(string $choiceId): self
    {
        return new self($choiceId, []);
    }
}
