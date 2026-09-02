<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/**
 * PHP style.
 *
 * Laravel's conventions, because half the PHP here is a Laravel application and a codebase
 * that formats its two halves differently is harder to read than one that picks a side.
 * `declare(strict_types=1)` is required rather than merely allowed — every file in the
 * kernel has it, and the determinism guarantees are easier to reason about when a string
 * cannot quietly become an int.
 */
return (new Config)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@PHP84Migration' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'phpdoc_align' => false,
        'phpdoc_separation' => false,
        'concat_space' => ['spacing' => 'one'],
        'not_operator_with_successor_space' => true,
        // `fn (` and `new Foo` without parentheses, matching what is already written here
        // and what Laravel writes, rather than what PER-CS prefers.
        'function_declaration' => ['closure_fn_spacing' => 'one'],
        'new_with_parentheses' => ['anonymous_class' => false, 'named_class' => false],
        // The heredocs in the migrations hold SQL, and its indentation is chosen to make the
        // SQL readable rather than to line up with the PHP around it.
        'heredoc_indentation' => false,
    ])
    ->setFinder(
        (new Finder)
            ->in([__DIR__ . '/packages', __DIR__ . '/apps/api/app', __DIR__ . '/apps/api/database', __DIR__ . '/apps/api/tests'])
            ->exclude(['vendor', 'node_modules'])
    );
