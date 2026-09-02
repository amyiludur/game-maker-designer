<?php

declare(strict_types=1);

namespace App\Support\Projectors;

use App\Models\Card;

/**
 * Derives a card's index columns from its document.
 *
 * The one place that writes them, always inside the same transaction as the document
 * (ADR-0001). The rules that make this pattern work rather than rot:
 *
 *  1. Writes go to the document; index columns follow, here.
 *  2. Nothing reads an index column to make a game decision — they exist for the card
 *     browser's facets and nothing else.
 *  3. They are droppable, because this can rebuild all of them from the documents, which
 *     `cards:reproject` does and a test asserts byte for byte.
 *
 * The temptation this exists to resist is adding a column and writing to it directly.
 */
final class CardProjector
{
    /** @return array<string, mixed> the index columns for a card document */
    public function project(Card $card): array
    {
        /** @var array<string, mixed> $document */
        $document = $card->document ?? [];
        $faces = $this->faces($document);
        $front = $faces[0] ?? [];

        return [
            'name' => $this->name($document, $faces),
            'card_type' => isset($front['type']) ? (string) $front['type'] : null,
            'faction' => isset($document['faction']) ? (string) $document['faction'] : null,
            'cost' => $this->integer($front['attributes']['cost'] ?? null),
            'traits' => $this->tags($faces, 'traits'),
            'keywords' => $this->keywords($faces),
            'search' => $this->search($document, $faces),
        ];
    }

    /** Write the index columns onto the model, without saving. */
    public function apply(Card $card): Card
    {
        foreach ($this->project($card) as $column => $value) {
            $card->setAttribute($column, $value);
        }

        return $card;
    }

    /**
     * A card is either flat or double-sided; both become a list of faces so nothing
     * downstream has to ask which shape it is looking at.
     *
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function faces(array $document): array
    {
        if (isset($document['sides']) && is_array($document['sides'])) {
            return array_values($document['sides']);
        }

        return [$document];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $faces
     */
    private function name(array $document, array $faces): ?string
    {
        // A double-sided card is searched for by either name, so both go in — "Aria Vance"
        // and "Nightjar" are the same card and a designer will look for whichever they
        // remember.
        $names = array_values(array_filter(array_map(
            static fn (array $face): ?string => isset($face['name']) ? (string) $face['name'] : null,
            $faces,
        )));

        if ($names === []) {
            return isset($document['name']) ? (string) $document['name'] : null;
        }

        return implode(' / ', $names);
    }

    /**
     * @param  list<array<string, mixed>>  $faces
     * @return list<string>
     */
    private function tags(array $faces, string $key): array
    {
        $tags = [];
        foreach ($faces as $face) {
            foreach ($face['attributes'][$key] ?? [] as $tag) {
                $tags[(string) $tag] = true;
            }
        }

        return array_keys($tags);
    }

    /**
     * @param  list<array<string, mixed>>  $faces
     * @return list<string>
     */
    private function keywords(array $faces): array
    {
        $keywords = [];
        foreach ($faces as $face) {
            foreach ($face['keywords'] ?? [] as $keyword) {
                if (isset($keyword['id'])) {
                    $keywords[(string) $keyword['id']] = true;
                }
            }
        }

        return array_keys($keywords);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $faces
     */
    private function search(array $document, array $faces): string
    {
        $parts = [$document['code'] ?? '', $document['flavor'] ?? ''];
        foreach ($faces as $face) {
            $parts[] = $face['name'] ?? '';
            $parts[] = $face['text'] ?? '';
            $parts[] = $face['textOverride'] ?? '';
            foreach ($face['abilities'] ?? [] as $ability) {
                $parts[] = $ability['text'] ?? '';
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_map(strval(...), $parts))) ?? '');
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
