<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Autowired, with two exclusions and one argument this package deliberately does
 * not answer.
 *
 * `VoucherRedemptions` holds a `Doctrine\DBAL\Connection`, and which database
 * that is is **not this module's business** — the same rule core states about
 * itself: a module has no opinion about whose data it is operating on, and the
 * application binds the tenant's connection in `config/services.yaml` beside
 * every other service that writes to a customer's database. Guessing here would
 * mean autowiring the control plane's connection and counting one shared set of
 * redemptions for every customer on the instance.
 */

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->load('Xivi\\Voucher\\', __DIR__ . '/../src/')
            ->exclude([
                // A value object and an exception: rules and answers, not
                // services. `VoucherCode` is entirely static and `VoucherExhausted`
                // has a private constructor, so the container could not build
                // either of them and has no reason to want to.
                __DIR__ . '/../src/Code/VoucherCode.php',
                __DIR__ . '/../src/Redemption/VoucherExhausted.php',
                __DIR__ . '/../src/XiviVoucherBundle.php',
            ]);
};
