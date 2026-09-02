<?php

declare(strict_types=1);

namespace Gmd\Kernel\Modifier;

/**
 * How a value got to where it is: "Attack 3 = 2 base +1 from Warhorn Bearer".
 *
 * This falls out of the layer walk for almost nothing, and it is the single most useful
 * thing the card inspector can show a designer who is asking why a number is wrong.
 */
final readonly class ModifierBreakdown
{
    /**
     * @param  list<array{layer: int, source: string, sourceName: string, mode: string, amount: mixed, result: mixed, duration: string}>  $steps
     */
    public function __construct(
        public string $instanceId,
        public string $attribute,
        public mixed $printed,
        public mixed $current,
        public array $steps = [],
    ) {}

    public function isModified(): bool
    {
        return $this->steps !== [];
    }

    /** One line per contribution, in the order they applied. */
    public function describe(): string
    {
        $parts = ['printed ' . json_encode($this->printed)];
        foreach ($this->steps as $step) {
            $parts[] = sprintf(
                '%s %s from %s (layer %d, %s)',
                $step['mode'],
                json_encode($step['amount']),
                $step['sourceName'],
                $step['layer'],
                $step['duration'],
            );
        }
        $parts[] = 'current ' . json_encode($this->current);

        return implode(' · ', $parts);
    }
}
