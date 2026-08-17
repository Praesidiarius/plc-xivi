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
            ['Key', 'Name', 'State', 'In this build', 'Decided'],
            array_map(fn (CatalogEntry $e): array => [
                $e->key,
                $this->name($e),
                $e->state->value,
                $e->isInBuild() ? 'yes' : 'no',
                // "never" rather than a blank: the default state is a decision
                // nobody has made yet, and that is the interesting part.
                $e->decision?->getUpdatedAt()->format('Y-m-d H:i') ?? 'never',
            ], $entries),
        );

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
