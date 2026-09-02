<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

/**
 * One level of an effect script in mid-resolution: which program, and how far into it.
 *
 * `items`/`index` carry loop position for `for_each`, `for_each_player` and `repeat`, so a
 * loop that is interrupted by a player choice resumes on the right iteration rather than
 * starting over.
 */
final readonly class StackFrame
{
    /**
     * @param  list<string>  $path   keys walked from the program root, e.g. ['2', 'then']
     * @param  list<mixed>|null  $items  loop collection, when this frame is a loop body
     * @param  array<string, mixed>  $vars  frame-local bindings ($each, $player, loop vars)
     */
    public function __construct(
        public ProgramRef $program,
        public array $path = [],
        public int $pc = 0,
        public ?array $items = null,
        public int $index = 0,
        public array $vars = [],
    ) {}

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return new self(
            $changes['program'] ?? $this->program,
            $changes['path'] ?? $this->path,
            $changes['pc'] ?? $this->pc,
            array_key_exists('items', $changes) ? $changes['items'] : $this->items,
            $changes['index'] ?? $this->index,
            $changes['vars'] ?? $this->vars,
        );
    }

    public function advanced(): self
    {
        return $this->with(['pc' => $this->pc + 1]);
    }
}
