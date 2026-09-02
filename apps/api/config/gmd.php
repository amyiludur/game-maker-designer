<?php

declare(strict_types=1);

/*
 | Where the platform's shared contracts live.
 |
 | The JSON Schemas and the worked example games are repository-level assets, not
 | application ones: the kernel's tests, the JS validator and this application all read the
 | same files, which is what stops three copies of "what a valid card is" from drifting.
 */
return [
    'schemas' => env('GMD_SCHEMAS_PATH', dirname(base_path(), 2) . '/schemas'),
    'examples' => env('GMD_EXAMPLES_PATH', dirname(base_path(), 2) . '/examples'),
    'snapshot_every' => (int) env('GMD_SNAPSHOT_EVERY', 20),
];
