<?php

declare(strict_types=1);

namespace App\Services;

/** The caller acted on a position that has since moved. */
final class StaleMatchVersion extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct("the match has moved on; it is now at version {$currentVersion}");
    }
}
