<?php

declare(strict_types=1);

use Gmd\Kernel\Diagnostics\BadDocument;
use Gmd\Kernel\State\Codec\CanonicalJson;

it('sorts object keys by byte value', function (): void {
    expect(CanonicalJson::encode(['b' => 1, 'a' => 2, 'C' => 3]))->toBe('{"C":3,"a":2,"b":1}');
});

it('produces identical bytes for maps built in different orders', function (): void {
    $one = ['zone' => 'p0.hand', 'code' => 'core-012', 'owner' => 'p0'];
    $other = ['owner' => 'p0', 'zone' => 'p0.hand', 'code' => 'core-012'];

    expect(CanonicalJson::encode($one))->toBe(CanonicalJson::encode($other));
});

it('preserves array order, which is game-significant', function (): void {
    // Zone arrays are ordered: index 0 is the top of the deck. Sorting them would be a
    // shuffle, so lists must never be reordered the way maps are.
    expect(CanonicalJson::encode(['i-p0-3', 'i-p0-1', 'i-p0-2']))
        ->toBe('["i-p0-3","i-p0-1","i-p0-2"]');
});

it('emits no insignificant whitespace', function (): void {
    expect(CanonicalJson::encode(['a' => [1, 2], 'b' => ['c' => true]]))
        ->toBe('{"a":[1,2],"b":{"c":true}}');
});

it('rejects floats outright', function (): void {
    CanonicalJson::encode(['attack' => 2.5]);
})->throws(BadDocument::class, 'floats are not representable');

it('names the path to a rejected float', function (): void {
    try {
        CanonicalJson::encode(['instances' => ['i-p0-1' => ['cost' => 1.5]]]);
        expect(false)->toBeTrue('expected a BadDocument');
    } catch (BadDocument $e) {
        expect($e->diagnostic()->context['path'])->toBe('/instances/i-p0-1/cost');
    }
});

it('distinguishes an empty object from an empty array', function (): void {
    expect(CanonicalJson::encode([]))->toBe('[]');
    expect(CanonicalJson::encode(new stdClass))->toBe('{}');
});

it('leaves slashes and unicode unescaped', function (): void {
    expect(CanonicalJson::encode(['ref' => 'card:core-024#a1.effect', 'name' => 'Vess of the Grey Ash']))
        ->toBe('{"name":"Vess of the Grey Ash","ref":"card:core-024#a1.effect"}');
    expect(CanonicalJson::encode(['z' => 'a/b']))->toBe('{"z":"a/b"}');
});

it('escapes what JSON requires', function (): void {
    expect(CanonicalJson::encode(['q' => "a\"b\\c\nd"]))->toBe('{"q":"a\"b\\\\c\nd"}');
});

it('round-trips through decode', function (): void {
    $value = ['a' => [1, 2, 3], 'b' => ['c' => 'x', 'd' => null], 'e' => true];

    expect(CanonicalJson::decode(CanonicalJson::encode($value)))->toBe($value);
});
