<?php

declare(strict_types=1);

namespace Gmd\Kernel\State\Codec;

use Gmd\Kernel\State\GameState;

/**
 * The conformance hash: "these two runs reached the same position".
 *
 * A golden replay asserts a hash at every checkpoint, on every machine and PHP version, and
 * — if a second kernel is ever written in another language (ADR-0002) — in another runtime.
 * That claim is only meaningful if what is hashed is pinned exactly, so this is a
 * specification rather than an implementation detail:
 *
 *   preimage = the schema-shaped state
 *            + systemDigest   (a position means nothing without the rules it was reached under)
 *            - log            (a presentation buffer, capped and truncated; hashing it would
 *                              make the hash depend on how much history we happen to keep)
 *
 * The engine's internal counters under `vars.__*` ARE included: the timestamp counter and
 * the id ordinals affect every future modifier ordering and instance id, so two positions
 * that differ in them are genuinely different positions.
 */
final class StateHasher
{
    public static function hash(GameState $state): string
    {
        return 'sha256:' . hash('sha256', self::preimage($state));
    }

    /** The exact bytes that get hashed. Exposed so a divergence can be diffed rather than guessed at. */
    public static function preimage(GameState $state): string
    {
        $document = StateCodec::encode($state);
        unset($document['log']);
        $document['systemDigest'] = $state->systemDigest;

        return CanonicalJson::encode($document);
    }
}
