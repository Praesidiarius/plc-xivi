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

namespace Xivi\ControlPlane\Command;

use App\Registry\Catalog\CatalogEntry;
use App\Registry\Catalog\ModuleCatalog;
use App\Registry\Pricing\ModulePricing;
use App\Registry\Pricing\PriceCurrency;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every module this build ships, and what state the platform has it in (XIV-7).
 *
 * Not `tenant:` anything: a module's state is one answer for the whole platform,
 * so asking it needs no tenant and naming one would suggest it could differ.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(name: 'module:list', description: 'List the modules in this build and their state')]
final readonly class ListModulesCommand
{
    public function __construct(
        private ModuleCatalog $catalog,
        private TranslatorInterface $translator,
        private PriceCurrency $currency,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $entries = $this->catalog->entries();

        if ($entries === []) {
            $io->warning('This build ships no modules.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Key', 'Name', 'State', 'Price', 'In this build', 'In the store', 'Decided'],
            array_map(fn (CatalogEntry $e): array => [
                $e->key,
                $this->name($e),
                $e->state->value,
                $this->price($e),
                $e->isInBuild() ? 'yes' : 'no',
                // Printed rather than left to be worked out from the two columns
                // to the left, because since XIV-101 there are three ways of
                // being no and the interesting one — published but unpriced — is
                // the one a reader is least likely to derive.
                $e->isOfferedInStore() ? 'yes' : 'no',
                // "never" rather than a blank: the default state is a decision
                // nobody has made yet, and that is the interesting part.
                $e->decision?->getUpdatedAt()->format('Y-m-d H:i') ?? 'never',
            ], $entries),
        );

        $unpriced = array_filter(
            $entries,
            static fn (CatalogEntry $e): bool => $e->state->isOfferedInStore() && !$e->price->pricing->isDecided(),
        );

        if ($unpriced !== []) {
            $io->warning(sprintf(
                'Published and unpriced, so not offered in the store: %s. A module with no price is '
                . 'not a free module — set one with `module:price`, or say `free` if that is the answer.',
                implode(', ', array_map(static fn (CatalogEntry $e): string => $e->key, $unpriced)),
            ));
        }

        $orphans = array_filter($entries, static fn (CatalogEntry $e): bool => !$e->isInBuild());

        if ($orphans !== []) {
            $io->warning(sprintf(
                'A state is recorded for %s, which this build does not ship. Either the module '
                . 'was removed from the code or the deploy is older than the decision.',
                implode(', ', array_map(static fn (CatalogEntry $e): string => sprintf('"%s"', $e->key), $orphans)),
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * What this deployment charges, in one cell (XIV-101).
     *
     * The undecided case gets a word of its own rather than a blank or a dash,
     * because a blank in a price column reads as free to anybody scanning — which
     * is the confusion the four cases exist to prevent, and a table is where it
     * would happen first.
     *
     * The amount is the stored decimal string with the currency code after it,
     * not a locale-formatted figure: a console reader wants what is in the row,
     * and grouping separators in a column somebody may be about to grep is a
     * courtesy nobody asked for.
     */
    private function price(CatalogEntry $entry): string
    {
        $price = $entry->price;

        if (!$price->costsMoney()) {
            return match ($price->pricing) {
                ModulePricing::Free => 'free',
                ModulePricing::NotForSale => 'not for sale',
                default => 'not priced yet',
            };
        }

        $code = $this->currency->code();

        return $code === null ? (string) $price->amount : sprintf('%s %s', $price->amount, $code);
    }

    /**
     * A blueprint's label is a translation key, resolved into the tenant's own
     * definitions at install time (XIV-8) — so out here, with no tenant, the
     * platform's default language is the only honest thing to print it in.
     */
    private function name(CatalogEntry $entry): string
    {
        $blueprint = $entry->blueprint;

        if ($blueprint === null) {
            return '—';
        }

        return $this->translator->trans($blueprint->label, [], $blueprint->domain());
    }
}
