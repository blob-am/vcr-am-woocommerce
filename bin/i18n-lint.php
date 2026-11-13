<?php

declare(strict_types=1);

/**
 * Checks every catalogue in a directory against the template.
 *
 * A string missing from a catalogue is not an error WordPress reports: the
 * merchant sees English in the middle of a translated screen. A placeholder
 * dropped from a translation is worse, because sprintf() then prints the
 * sentence without the order number or the error it was meant to carry.
 * Neither can be seen by reading a diff, so both are refused here.
 *
 * Usage: php bin/i18n-lint.php <template.pot> <directory with .po files>
 */

use Gettext\Translation;
use Gettext\Translations;

$autoload = __DIR__ . '/../vendor-bin/i18n/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "i18n bin context not installed. Run: composer bin i18n install\n");
    exit(1);
}

require $autoload;

[$potPath, $directory] = [$argv[1] ?? '', $argv[2] ?? ''];

if (! is_file($potPath) || ! is_dir($directory)) {
    fwrite(STDERR, "usage: php bin/i18n-lint.php <template.pot> <directory with .po files>\n");
    exit(1);
}

/**
 * The printf directives of a string, sorted, so two strings that carry the
 * same directives in a different order compare equal.
 *
 * @return list<string>
 */
function placeholders(string $text): array
{
    preg_match_all('/%(?:\d+\$)?[sdf]/', $text, $matches);
    $found = $matches[0];
    sort($found);

    return $found;
}

function key_of(Translation $entry): string
{
    return $entry->getContext() . "\x04" . $entry->getOriginal();
}

$template = Translations::fromPoFile($potPath);
$expected = [];
foreach ($template as $entry) {
    $expected[key_of($entry)] = $entry;
}

$catalogues = glob($directory . '/*.po');
if ($catalogues === false || $catalogues === []) {
    fwrite(STDERR, "error: no .po catalogue found in {$directory}\n");
    exit(1);
}

$problems = [];

foreach ($catalogues as $path) {
    $name      = basename($path);
    $catalogue = Translations::fromPoFile($path);
    $forms     = $catalogue->getPluralForms();

    if ($forms === null) {
        $problems[] = "{$name}: no Plural-Forms header";

        continue;
    }

    $pluralCount = (int) $forms[0];
    $seen        = [];

    foreach ($catalogue as $entry) {
        $key        = key_of($entry);
        $seen[$key] = true;
        $label      = "{$name}: \"{$entry->getOriginal()}\"";

        if (! isset($expected[$key])) {
            $problems[] = "{$label} is not in the template any more";

            continue;
        }

        if (in_array('fuzzy', $entry->getFlags(), true)) {
            $problems[] = "{$label} is marked fuzzy";
        }

        if (trim($entry->getTranslation()) === '') {
            $problems[] = "{$label} has no translation";

            continue;
        }

        if (placeholders($entry->getTranslation()) !== placeholders($entry->getOriginal())) {
            $problems[] = "{$label} does not carry the same placeholders as its translation";
        }

        if (! $entry->hasPlural()) {
            continue;
        }

        // Form 0 is getTranslation(); the rest are the plural translations.
        $plurals = $entry->getPluralTranslations();
        if (count($plurals) !== $pluralCount - 1) {
            $problems[] = sprintf('%s has %d plural forms, the language needs %d', $label, count($plurals) + 1, $pluralCount);
        }

        foreach ($plurals as $plural) {
            if (trim($plural) === '') {
                $problems[] = "{$label} has an empty plural form";
            } elseif (placeholders($plural) !== placeholders($entry->getPlural())) {
                $problems[] = "{$label} has a plural form with different placeholders";
            }
        }
    }

    foreach ($expected as $key => $entry) {
        if (! isset($seen[$key])) {
            $problems[] = "{$name}: \"{$entry->getOriginal()}\" is missing";
        }
    }
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    fwrite(STDERR, sprintf("error: %d translation problem(s)\n", count($problems)));
    exit(1);
}

printf("Translations OK: %d catalogue(s), %d strings each.\n", count($catalogues), count($expected));
