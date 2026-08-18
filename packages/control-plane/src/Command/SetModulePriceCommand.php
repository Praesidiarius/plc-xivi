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

use App\Registry\Catalog\ModuleCatalog;
use App\Registry\Pricing\ModulePrice;
use App\Registry\Pricing\ModulePricing;
use App\Registry\Pricing\PriceCurrency;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What this deployment charges for a module, from a console (XIV-101).
 *
 * The screen at `/control/modules` is what the ticket asked for and is where an
 * operator will actually do this. This exists beside it for the reason §6.3 gives
 * about the store and `tenant:module:install`: **a page is not a reason to take a
 * command away.** A headless deployment has no browser pointed at the control
 * plane, a first install is scripted, and the one thing worse than an
 * administration surface with no console equivalent is one that had a console
 * equivalent until somebody drew a page.
 *
 * It writes through the same {@see ModuleCatalog::priceAt()} the screen does, so
 * the two cannot disagree about what a valid price is, about zero being refused,
 * or about the rounding.
 *
 *     bin/console module:price invoice priced 49.00
 *     bin/console module:price contact free
 *     bin/console module:price article not_for_sale
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'module:price',
    description: 'Set what this deployment charges for a module',
)]
final readonly class SetModulePriceCommand
{
    public function __construct(
        private ModuleCatalog $catalog,
        private PriceCurrency $currency,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Module key, e.g. "invoice"')]
        string $module,
        #[Argument(description: 'unpriced, free, priced or not_for_sale')]
        ModulePricing $pricing,
        #[Argument(description: 'The amount, for "priced" and only for it')]
        ?string $amount = null,
    ): int {
        // Both mistakes are worth their own sentence rather than one message
        // about the pair. Somebody who typed `module:price invoice priced` and
        // stopped has forgotten the number; somebody who typed
        // `module:price invoice free 49.00` believes a free module can carry a
        // price, and telling them "arguments do not match" would leave them
        // believing it.
        if ($pricing->needsAmount() && ($amount === null || trim($amount) === '')) {
            $io->error(sprintf('"%s" needs an amount. Say how much, or use "free".', $pricing->value));

            return Command::INVALID;
        }

        if (!$pricing->needsAmount() && $amount !== null && trim($amount) !== '') {
            $io->error(sprintf(
                '"%s" carries no amount, so %s would be a number nothing reads. '
                . 'Use "priced" if it costs money.',
                $pricing->value,
                $amount,
            ));

            return Command::INVALID;
        }

        $before = $this->catalog->price($module);

        try {
            $price = $pricing->needsAmount()
                ? ModulePrice::of(trim((string) $amount))
                : ModulePrice::fromStorage($pricing, null);

            $this->catalog->priceAt($module, $price);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($before->equals($price)) {
            $io->info(sprintf('Module "%s" was already %s. Nothing changed.', $module, $this->describe($price)));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Module "%s": %s is now %s.',
            $module,
            $this->describe($before),
            $this->describe($price),
        ));

        // The sentence §6.2 makes about state, which transfers to a price without
        // a word changed and matters more here: somebody raising a price is
        // entitled to know it is not a bill.
        $io->text(
            'Nothing was installed or uninstalled by this. A price says what a module costs from '
            . 'here on; every customer who already has it keeps it, on whatever terms they got it.',
        );

        if (!$this->catalog->state($module)->isOfferedInStore() && $price->mayBeOffered()) {
            $io->text(sprintf(
                'It is still not in the store: "%s" is in development. `module:state %s published` is the other half.',
                $module,
                $module,
            ));
        }

        return Command::SUCCESS;
    }

    /** The price as a sentence fragment, so the two above read as English. */
    private function describe(ModulePrice $price): string
    {
        if (!$price->costsMoney()) {
            return match ($price->pricing) {
                ModulePricing::Free => 'free',
                ModulePricing::NotForSale => 'not for sale',
                default => 'not priced',
            };
        }

        $code = $this->currency->code();

        // The bare number when nobody has set PRICE_CURRENCY, and no invented
        // default: §8.6's argument that a guessed currency is wrong quietly.
        return $code === null ? (string) $price->amount : sprintf('%s %s', $price->amount, $code);
    }
}
