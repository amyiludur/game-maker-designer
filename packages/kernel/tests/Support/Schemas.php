<?php

declare(strict_types=1);

namespace Gmd\Kernel\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * The repository's real JSON Schemas, wired up for tests.
 *
 * The kernel's own output is checked against the same documents the API validates writes
 * with and the client validates edits with (doc 13). One contract, one place.
 */
final class Schemas
{
    private static ?Validator $validator = null;

    public static function validator(): Validator
    {
        if (self::$validator === null) {
            $validator = new Validator;
            $validator->resolver()?->registerPrefix(
                'https://game-maker-designer.dev/schemas/',
                self::directory(),
            );
            self::$validator = $validator;
        }

        return self::$validator;
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 4) . '/schemas';
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>  human-readable violations, empty when the document is valid
     */
    public static function violations(array $document, string $schema): array
    {
        $result = self::validator()->validate(
            json_decode(json_encode($document, JSON_THROW_ON_ERROR), false),
            'https://game-maker-designer.dev/schemas/' . $schema . '.schema.json',
        );

        if ($result->isValid()) {
            return [];
        }

        $formatted = (new ErrorFormatter)->format($result->error(), true);
        $violations = [];
        foreach ($formatted as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $violations[] = ($pointer === '' ? '/' : $pointer) . ': ' . $message;
            }
        }

        return $violations;
    }
}
