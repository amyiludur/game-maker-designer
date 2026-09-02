<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

/** One seat: who is in it, and what they brought. */
final readonly class SeatSetup
{
    /**
     * @param  array<string, mixed>  $deck  the deck document: identity plus card counts
     */
    public function __construct(
        public int $seat,
        public array $deck,
        public ?string $label = null,
        public ?string $agent = null,
    ) {}

    public function side(): string
    {
        return Side::player($this->seat);
    }

    public function identityCode(): ?string
    {
        $identity = $this->deck['identity'] ?? null;

        return is_string($identity) ? $identity : null;
    }

    /**
     * The deck as a flat list of card codes, in document order, expanded by count.
     *
     * Order matters: instance ids are allocated from it before any shuffle, so they are the
     * same whatever the seed, and a state dump is readable.
     *
     * @return list<string>
     */
    public function cardCodes(): array
    {
        $codes = [];
        foreach ($this->deck['cards'] ?? [] as $entry) {
            for ($i = 0; $i < (int) ($entry['count'] ?? 1); $i++) {
                $codes[] = (string) $entry['code'];
            }
        }

        return $codes;
    }
}
