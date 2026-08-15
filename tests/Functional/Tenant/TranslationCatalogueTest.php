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

namespace App\Tests\Functional\Tenant;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Every language says everything the source language says (XIV-8).
 *
 * A missing translation is the quietest kind of bug this project can ship: the
 * fallback serves the English sentence, so the page still works and still reads
 * — one paragraph of it is simply in the wrong language, on somebody else's
 * screen, in a country nobody here is looking at. Nothing fails, nobody
 * notices, and it stays that way for a year.
 *
 * Comparing the catalogues is what turns that into a red build. It is also why
 * the fallback is worth keeping: the check catches the omission, so the fallback
 * only ever has to cover the minutes between writing a key and translating it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TranslationCatalogueTest extends KernelTestCase
{
    /** The language everything is written in first. */
    private const string SOURCE = 'en';

    public function testEveryLanguageTranslatesEverythingTheSourceSays(): void
    {
        $translator = $this->translator();
        $source = $translator->getCatalogue(self::SOURCE);

        foreach ($this->otherLocales() as $locale) {
            $catalogue = $translator->getCatalogue($locale);

            foreach ($source->getDomains() as $domain) {
                $missing = array_diff(
                    array_keys($source->all($domain)),
                    array_keys($catalogue->all($domain)),
                );

                self::assertSame([], array_values($missing), sprintf(
                    "These keys have no %s translation in the \"%s\" domain:\n  %s",
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
        $translator = $this->translator();
        $source = $translator->getCatalogue(self::SOURCE);

        foreach ($this->otherLocales() as $locale) {
            $catalogue = $translator->getCatalogue($locale);

            foreach ($catalogue->getDomains() as $domain) {
                $orphans = array_diff(
                    array_keys($catalogue->all($domain)),
                    array_keys($source->all($domain)),
                );

                self::assertSame([], array_values($orphans), sprintf(
                    "These %s keys in the \"%s\" domain are not in the source language:\n  %s",
                    $locale,
                    $domain,
                    implode("\n  ", $orphans),
                ));
            }
        }
    }

    /** The check is only worth anything if the catalogues have something in them. */
    public function testTheSourceCatalogueIsNotEmpty(): void
    {
        $source = $this->translator()->getCatalogue(self::SOURCE);

        self::assertNotSame([], $source->getDomains());
        self::assertGreaterThan(0, \count($source->all('messages')));
    }

    /** @return list<string> */
    private function otherLocales(): array
    {
        self::bootKernel();

        /** @var list<string> $enabled */
        $enabled = self::getContainer()->getParameter('kernel.enabled_locales');

        self::assertContains(self::SOURCE, $enabled, 'the source language has to be one of the enabled ones');

        return array_values(array_filter($enabled, static fn (string $l): bool => $l !== self::SOURCE));
    }

    /**
     * The catalogues, which are TranslatorBagInterface's business rather than
     * TranslatorInterface's — the latter only translates.
     */
    private function translator(): TranslatorBagInterface
    {
        self::bootKernel();

        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof TranslatorBagInterface);

        return $translator;
    }
}
