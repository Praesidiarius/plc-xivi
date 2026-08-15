<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every language says everything the source language says (XIV-8).
 *
 * A missing translation is the quietest kind of bug this project can ship: the
 * fallback serves the English sentence, so the page still works and still reads
 * — one paragraph of it is simply in the wrong language, on somebody else's
 * screen, in a country nobody here is looking at. Nothing fails, nobody
 * notices, and it stays that way for a year.
 *
 * **It reads the catalogue files rather than the translator**, and that is the
 * point rather than a shortcut. The translator hands back everything merged,
 * including the hundreds of constraint messages Symfony ships — comparing those
 * would mean this suite going red because a framework release translated
 * something late, which is neither our bug nor our business. These files are the
 * ones this project is answerable for.
 *
 * A unit test for the same reason: it needs no kernel and no database, so it
 * costs milliseconds and runs first.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TranslationCatalogueTest extends TestCase
{
    /** The language everything is written in first. */
    private const string SOURCE = 'en';

    /** Everything else this build ships. */
    private const array TRANSLATIONS = ['de'];

    public function testEveryLanguageTranslatesEverythingTheSourceSays(): void
    {
        foreach ($this->catalogues() as $domain => $path) {
            $source = self::keysOf(sprintf($path, self::SOURCE));

            foreach (self::TRANSLATIONS as $locale) {
                $missing = array_values(array_diff($source, self::keysOf(sprintf($path, $locale))));

                self::assertSame([], $missing, sprintf(
                    "These keys have no %s translation in \"%s\":\n  %s",
                    $locale,
                    $domain,
                    implode("\n  ", $missing),
                ));
            }
        }
    }

    /**
     * And nothing extra, which is the same bug pointing the other way: a key
     * translated into German and then renamed or deleted in English is a line
     * nobody will ever see again, sitting in the file looking like work.
     */
    public function testNoLanguageTranslatesSomethingTheSourceNoLongerSays(): void
    {
        foreach ($this->catalogues() as $domain => $path) {
            $source = self::keysOf(sprintf($path, self::SOURCE));

            foreach (self::TRANSLATIONS as $locale) {
                $orphans = array_values(array_diff(self::keysOf(sprintf($path, $locale)), $source));

                self::assertSame([], $orphans, sprintf(
                    "These %s keys in \"%s\" are not in the source language:\n  %s",
                    $locale,
                    $domain,
                    implode("\n  ", $orphans),
                ));
            }
        }
    }

    /** The check is only worth anything if it is looking at something. */
    public function testTheCataloguesAreFound(): void
    {
        $catalogues = $this->catalogues();

        self::assertArrayHasKey('messages', $catalogues, 'the application catalogue');
        self::assertArrayHasKey('xivi', $catalogues, "the engine's own");
        self::assertArrayHasKey('contact', $catalogues, "a module's own");
        self::assertGreaterThan(100, \count(self::keysOf(sprintf($catalogues['messages'], 'en'))));
    }

    /**
     * Every catalogue this project ships, as a printf pattern with the locale
     * left open.
     *
     * Found on disk rather than listed, so a new domain — the next module to
     * ship one of its own — is covered without this file being edited.
     *
     * @return array<string, string> domain => path pattern
     */
    private function catalogues(): array
    {
        $root = \dirname(__DIR__, 2);
        $found = [];

        foreach ([$root . '/translations', ...(glob($root . '/packages/*/translations') ?: [])] as $directory) {
            foreach (glob($directory . '/*.' . self::SOURCE . '.yaml') ?: [] as $file) {
                $domain = basename($file, '.' . self::SOURCE . '.yaml');
                $found[$domain] = \dirname($file) . '/' . $domain . '.%s.yaml';
            }
        }

        return $found;
    }

    /**
     * A catalogue's keys, flattened the way the translator flattens them.
     *
     * A missing file is no keys rather than an error: that is exactly what the
     * assertions above are for, and it makes "the German file was deleted" read
     * as every key missing instead of as a stack trace.
     *
     * @return list<string>
     */
    private static function keysOf(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($path) ?? [];
        $keys = [];

        self::flatten($parsed, '', $keys);
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $into
     */
    private static function flatten(array $values, string $prefix, array &$into): void
    {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (\is_array($value)) {
                self::flatten($value, $path, $into);

                continue;
            }

            $into[] = $path;
        }
    }
}
