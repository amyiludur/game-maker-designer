<?php

declare(strict_types=1);

namespace App\Services;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Authoritative document validation.
 *
 * The same JSON Schemas the client validates with, so the two cannot disagree about what a
 * valid card is — the client's copy is a convenience that gives instant feedback, and this
 * is the rule (doc 02).
 *
 * Violations come back as JSON Pointers so the editor can put the message next to the field
 * that caused it rather than at the top of the form.
 */
final class SchemaValidator
{
    private ?Validator $validator = null;

    public function __construct(private readonly string $directory) {}

    /**
     * @param  array<string, mixed>  $document
     * @return list<array{pointer: string, message: string}>
     */
    public function violations(array $document, string $schema): array
    {
        $result = $this->validator()->validate(
            json_decode(json_encode($document, JSON_THROW_ON_ERROR), false),
            'https://game-maker-designer.dev/schemas/' . $schema . '.schema.json',
        );

        if ($result->isValid()) {
            return [];
        }

        $violations = [];
        foreach ((new ErrorFormatter)->format($result->error(), true) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $violations[] = ['pointer' => $pointer === '' ? '/' : $pointer, 'message' => $message];
            }
        }

        return $violations;
    }

    /** @param array<string, mixed> $document */
    public function isValid(array $document, string $schema): bool
    {
        return $this->violations($document, $schema) === [];
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $this->validator = new Validator;
            $this->validator->resolver()?->registerPrefix(
                'https://game-maker-designer.dev/schemas/',
                $this->directory,
            );
        }

        return $this->validator;
    }
}
