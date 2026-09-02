<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Game;
use Gmd\Kernel\System\SystemDocument;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Validates a card against both schemas that govern it.
 *
 * `card.schema.json` says what every card has; the compiled schema for the card's *type*
 * says what its attributes are, and that one is game data — Emberfall's characters have a
 * cost of 0–10 because Emberfall says so, not because this application knows anything about
 * costs. The card schema's own description says attributes are "additionally validated
 * against the compiled schema for its card type", and until this existed they were not: a
 * character could be saved with a cost of 40.
 *
 * Violations come back as JSON Pointers into the card document, so the editor can put each
 * message next to the field that caused it.
 */
final class CardValidator
{
    public function __construct(
        private readonly SchemaValidator $schemas,
        private readonly GameCompiler $compiler,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @return list<array{pointer: string, message: string}>
     */
    public function violations(Game $game, array $document): array
    {
        $version = $game->currentVersion;
        if ($version === null) {
            return $this->schemas->violations($this->normalise($document), 'card');
        }

        return $this->violationsAgainst($this->compiler->compile($version), $document);
    }

    /**
     * The same two checks against a system that is not the game's current one.
     *
     * This is what lets the impact report answer the question a designer actually has before
     * changing a card type: which of my cards would stop being valid?
     *
     * @param  array<string, mixed>  $document
     * @return list<array{pointer: string, message: string}>
     */
    public function violationsAgainst(SystemDocument $system, array $document): array
    {
        $violations = $this->schemas->violations($this->normalise($document), 'card');

        // The type schema is only meaningful for a document that is otherwise a card; a
        // second wave of errors about attributes on a document missing its `code` would
        // bury the one that matters.
        if ($violations !== []) {
            return $violations;
        }

        return $this->attributeViolations($system, $document);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array{pointer: string, message: string}>
     */
    private function attributeViolations(SystemDocument $system, array $document): array
    {
        $types = $this->compiler->descriptors($system)['cardTypes'] ?? [];

        $violations = [];

        // Faces, so a double-sided card is checked on both — each side is a card type of
        // its own and they need not be the same one.
        foreach ($this->faces($document) as $pointer => $face) {
            $type = $face['type'] ?? null;
            if (! is_string($type)) {
                continue;
            }

            $schema = $types[$type]['schema'] ?? null;
            if (! is_array($schema)) {
                $violations[] = [
                    'pointer' => $pointer === '' ? '/type' : $pointer . '/type',
                    'message' => "\"{$type}\" is not a card type in this game",
                ];

                continue;
            }

            foreach ($this->against($face['attributes'] ?? [], $schema) as $violation) {
                $violations[] = [
                    'pointer' => $pointer . '/attributes' . $violation['pointer'],
                    'message' => $violation['message'],
                ];
            }
        }

        return $violations;
    }

    /**
     * Restores the empty objects PHP loses.
     *
     * A document read back from jsonb — or from `json_decode(..., true)` — cannot tell
     * `{}` from `[]`, so a card whose attributes are legitimately empty encodes back as an
     * array and fails `"type": "object"`. The schema says `attributes` is an object, so
     * that ambiguity is resolvable here even though it is not resolvable in general.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function normalise(array $document): array
    {
        if (isset($document['sides']) && is_array($document['sides'])) {
            foreach ($document['sides'] as $side => $face) {
                if (is_array($face) && ($face['attributes'] ?? null) === []) {
                    $document['sides'][$side]['attributes'] = new \stdClass;
                }
            }

            return $document;
        }

        if (($document['attributes'] ?? null) === []) {
            $document['attributes'] = new \stdClass;
        }

        return $document;
    }

    /**
     * The document's faces, keyed by the JSON Pointer prefix each one lives at.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, array<string, mixed>>
     */
    private function faces(array $document): array
    {
        // Called with the raw document, so `attributes` here may still be `[]`; `against()`
        // is what turns that into an empty object for the type schema.
        if (isset($document['sides']) && is_array($document['sides'])) {
            $faces = [];
            foreach ($document['sides'] as $side => $face) {
                if (is_array($face)) {
                    $faces['/sides/' . $side] = $face;
                }
            }

            return $faces;
        }

        return ['' => $document];
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     * @param  array<string, mixed>  $schema
     * @return list<array{pointer: string, message: string}>
     */
    private function against(array $value, array $schema): array
    {
        $result = (new Validator)->validate(
            Helper::toJSON($value === [] ? new \stdClass : $value),
            Helper::toJSON($schema),
        );

        if ($result->isValid()) {
            return [];
        }

        $violations = [];
        foreach ((new ErrorFormatter)->format($result->error(), true) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $violations[] = ['pointer' => $pointer, 'message' => $message];
            }
        }

        return $violations;
    }
}
