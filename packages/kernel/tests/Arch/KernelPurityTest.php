<?php

declare(strict_types=1);

/*
 * Determinism enforced by tests rather than intent (ADR-0005).
 *
 * Every one of these bans has the same shape of failure behind it: the kernel keeps
 * working, keeps returning plausible numbers, and quietly stops being reproducible. A
 * shuffle seeded from the system generator still shuffles. A tiebreak on microtime() still
 * breaks ties. Nothing fails until a replay from three months ago does not reproduce, and
 * by then the cause is a hundred commits back.
 */

/** @return list<string> */
function kernelSourceFiles(): array
{
    $root = dirname(__DIR__, 2) . '/src';
    $files = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * Every *global function call* the file makes, as (name, line) pairs.
 *
 * Read from the tokeniser rather than a regex, so a banned name cannot hide in a comment,
 * and narrowed to actual calls, so a method legitimately named shuffle() on our own RNG
 * interface is not mistaken for PHP's shuffle().
 *
 * @return list<array{0: string, 1: int}>
 */
function functionCallsIn(string $path): array
{
    $tokens = array_values(array_filter(
        token_get_all((string) file_get_contents($path)),
        static fn (array|string $t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $memberOperators = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];
    $declarations = [T_FUNCTION, T_NEW, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_CONST];

    $calls = [];
    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        if (($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }
        $previous = $tokens[$i - 1] ?? null;
        if (is_array($previous) && in_array($previous[0], [...$memberOperators, ...$declarations], true)) {
            continue;
        }
        $calls[] = [$token[1], $token[2]];
    }

    return $calls;
}

it('has source files to check', function (): void {
    expect(kernelSourceFiles())->not->toBeEmpty();
});

it('actually sees the calls a file makes', function (): void {
    // A scanner that silently matches nothing would pass every ban below while enforcing
    // none of them, so prove it finds calls that are genuinely there, and skips the method
    // declarations and member calls that are not.
    $rng = dirname(__DIR__, 2) . '/src/Rng/Pcg64Rng.php';
    $names = array_column(functionCallsIn($rng), 0);

    expect($names)->toContain('unpack')->toContain('substr');
    expect($names)->not->toContain('shuffle');   // declared as a method, never called as a function
    expect($names)->not->toContain('nextInt');   // called on $this, not globally
});

it('never reaches for a random source outside the seeded stream', function (): void {
    $banned = ['rand', 'mt_rand', 'random_int', 'random_bytes', 'shuffle', 'str_shuffle', 'array_rand', 'uniqid'];
    $offences = [];

    foreach (kernelSourceFiles() as $path) {
        foreach (functionCallsIn($path) as [$name, $line]) {
            if (in_array(strtolower($name), $banned, true)) {
                $offences[] = basename($path) . ":{$line} calls {$name}()";
            }
        }
    }

    expect($offences)->toBe([]);
});

it('never reads a clock or a locale', function (): void {
    $banned = ['time', 'microtime', 'hrtime', 'date', 'getdate', 'mktime', 'strtotime', 'setlocale', 'localeconv'];
    $offences = [];

    foreach (kernelSourceFiles() as $path) {
        foreach (functionCallsIn($path) as [$name, $line]) {
            if (in_array(strtolower($name), $banned, true)) {
                $offences[] = basename($path) . ":{$line} calls {$name}()";
            }
        }
    }

    expect($offences)->toBe([]);
});

it('never sorts without a total order', function (): void {
    // sort()/usort() on equal keys is not stable in PHP, and iterating an unordered map
    // relies on insertion order. Where order affects outcome the kernel sorts explicitly on
    // a tiebreak that cannot tie — instance id, seat, or the timestamp counter.
    $banned = ['array_multisort', 'shuffle', 'asort', 'arsort'];
    $offences = [];

    foreach (kernelSourceFiles() as $path) {
        foreach (functionCallsIn($path) as [$name, $line]) {
            if (in_array(strtolower($name), $banned, true)) {
                $offences[] = basename($path) . ":{$line} calls {$name}()";
            }
        }
    }

    expect($offences)->toBe([]);
});

it('does no I/O', function (): void {
    $banned = [
        'file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'fread', 'curl_init',
        'unlink', 'mkdir', 'glob', 'scandir', 'readfile', 'include', 'require',
        'getenv', 'putenv', 'exec', 'shell_exec', 'system', 'proc_open', 'passthru',
    ];
    $offences = [];

    foreach (kernelSourceFiles() as $path) {
        foreach (functionCallsIn($path) as [$name, $line]) {
            if (in_array(strtolower($name), $banned, true)) {
                $offences[] = basename($path) . ":{$line} calls {$name}()";
            }
        }
    }

    // The kernel is handed parsed documents; the harness and the application read files.
    // That boundary is what lets the same kernel serve a CLI, an HTTP request, a queue
    // worker and a test with no bootstrapping.
    expect($offences)->toBe([]);
});

it('confines the random engine to one file', function (): void {
    $offences = [];
    foreach (kernelSourceFiles() as $path) {
        if (basename($path) === 'Pcg64Rng.php') {
            continue;
        }
        if (str_contains((string) file_get_contents($path), 'Random\\Engine')) {
            $offences[] = basename($path);
        }
    }

    expect($offences)->toBe([]);
});

it('does not depend on a framework', function (): void {
    $offences = [];
    foreach (kernelSourceFiles() as $path) {
        $source = (string) file_get_contents($path);
        foreach (['Illuminate\\', 'Symfony\\', 'Laravel\\'] as $namespace) {
            if (str_contains($source, $namespace)) {
                $offences[] = basename($path) . ' references ' . $namespace;
            }
        }
    }

    expect($offences)->toBe([]);
});

it('holds no mutable global state', function (): void {
    // A static cache in the kernel would make two matches in one process able to see each
    // other, which is exactly the bug a fuzz run is least likely to reproduce.
    $offences = [];
    foreach (kernelSourceFiles() as $path) {
        $source = (string) file_get_contents($path);
        if (preg_match_all('/^\s*(?:private|protected|public)\s+static\s+(?!function|readonly)/m', $source, $matches)) {
            $offences[] = basename($path) . ': ' . count($matches[0]) . ' static property/properties';
        }
    }

    expect($offences)->toBe([]);
});
