<?php

declare(strict_types=1);

/**
 * `assets` sat in .distignore for a while, so the one script the plugin
 * enqueues was missing from every released ZIP and its button never appeared.
 * Nothing failed: a 404 on a script is silent.
 */
it('ships every file the plugin asks the browser to load', function (): void {
    $root = dirname(__DIR__, 2);

    $ignored = array_filter(
        array_map('trim', file($root . '/.distignore', FILE_IGNORE_NEW_LINES) ?: []),
        static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    );

    $referenced = [];
    $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

    foreach ($sources as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/plugins_url\\(\\s*'([^']+)'/", (string) file_get_contents($file->getPathname()), $matches);
        array_push($referenced, ...$matches[1]);
    }

    expect($referenced)->not->toBeEmpty();

    foreach ($referenced as $path) {
        expect(is_file($root . '/' . $path))->toBeTrue("{$path} is enqueued but does not exist");

        foreach (explode('/', $path) as $segment) {
            expect(in_array($segment, $ignored, true))
                ->toBeFalse("{$path} is enqueued, but .distignore keeps \"{$segment}\" out of the ZIP");
        }
    }
});
