<?php

declare(strict_types=1);

namespace Gmd\Kernel\State\Codec;

use Gmd\Kernel\Diagnostics\BadDocument;

/**
 * One JSON encoding, chosen so that equal states produce equal bytes on any machine.
 *
 * Conformance replays assert state hashes across machines, PHP versions and — if a
 * TypeScript kernel is ever built (ADR-0002) — across languages. That only means anything
 * if "the same state" has exactly one byte representation, so this is a specification:
 *
 *  - object keys sorted by byte value, ascending;
 *  - no insignificant whitespace;
 *  - integers emitted as integers; floats rejected outright, because rules maths is
 *    integer-only (ADR-0005) and a float in the state is a bug worth failing loudly on;
 *  - slashes and unicode left unescaped, so the bytes are the shortest faithful form;
 *  - a PHP list encodes as a JSON array, any other array as a JSON object, and an empty
 *    JSON object must be written as \stdClass (an empty PHP array is ambiguous, so we
 *    resolve it as `[]` and require callers to be explicit about the other case).
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return self::write($value);
    }

    /** Decode into arrays, rejecting anything that would not round-trip. */
    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw BadDocument::because('not valid JSON: ' . $e->getMessage());
        }
    }

    private static function write(mixed $value, string $path = ''): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            throw BadDocument::because(
                'floats are not representable in canonical state JSON; rules maths is integer-only',
                ['path' => $path === '' ? '/' : $path, 'value' => (string) $value],
            );
        }
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if ($value instanceof \stdClass) {
            return self::writeObject((array) $value, $path);
        }
        if ($value instanceof \JsonSerializable) {
            return self::write($value->jsonSerialize(), $path);
        }
        if (is_array($value)) {
            return array_is_list($value)
                ? self::writeList($value, $path)
                : self::writeObject($value, $path);
        }

        throw BadDocument::because(
            'value of type ' . get_debug_type($value) . ' cannot be canonically encoded',
            ['path' => $path === '' ? '/' : $path],
        );
    }

    /** @param list<mixed> $items */
    private static function writeList(array $items, string $path): string
    {
        $parts = [];
        foreach ($items as $i => $item) {
            $parts[] = self::write($item, $path . '/' . $i);
        }

        return '[' . implode(',', $parts) . ']';
    }

    /** @param array<array-key, mixed> $map */
    private static function writeObject(array $map, string $path): string
    {
        // PHP silently turns numeric string keys into ints, so sort on the string form but
        // keep the original key to look the value up by.
        $keys = array_keys($map);
        usort($keys, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));

        $parts = [];
        foreach ($keys as $key) {
            $name = (string) $key;
            $parts[] = json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                . ':' . self::write($map[$key], $path . '/' . $name);
        }

        return '{' . implode(',', $parts) . '}';
    }
}
