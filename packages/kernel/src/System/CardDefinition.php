<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\Diagnostics\BadDocument;

/**
 * A card as designed: the immutable definition every instance of it shares.
 *
 * The kernel never mutates one of these. A buffed character is an instance with a modifier
 * pointing at it, not an edited card — which is why two copies of the same card can have
 * different attack values and still be the same card.
 */
final readonly class CardDefinition
{
    /** @param array<string, CardFace> $faces */
    public function __construct(
        public string $code,
        public array $faces,
        public ?string $faction = null,
        public ?string $rarity = null,
        public ?string $setId = null,
        public int $quantity = 1,
    ) {}

    public function face(string $face = 'front'): CardFace
    {
        return $this->faces[$face]
            ?? throw BadDocument::because("card \"{$this->code}\" has no face \"{$face}\"");
    }

    public function isDoubleSided(): bool
    {
        return count($this->faces) > 1;
    }

    public function otherFace(string $face): string
    {
        return $face === 'front' ? 'back' : 'front';
    }

    public function name(string $face = 'front'): string
    {
        return $this->face($face)->name;
    }
}
