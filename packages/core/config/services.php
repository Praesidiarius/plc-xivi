<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Core's services are autowired, but two of their arguments cannot be resolved
 * here: the entity manager and the connection. Core has no opinion about which
 * database it operates on — the application binds those to the tenant's, in
 * config/services.yaml. Guessing would mean writing customer records into the
 * control plane.
 */

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->load('Xivi\\Core\\', __DIR__ . '/../src/')
            ->exclude([
                __DIR__ . '/../src/Entity/',
                __DIR__ . '/../src/Module/{ModuleBlueprint,CollectionBlueprint,FieldBlueprint}.php',
                // Declarations and answers, not services: a module writes the
                // first in its blueprint and the resolver hands back the second
                // (XIV-39).
                __DIR__ . '/../src/Mail/{MailRecipient,Recipient}.php',
                __DIR__ . '/../src/Money/Amount.php',
                __DIR__ . '/../src/Payment/PaymentTerms.php',
                // What came of reading one string as a phone number, and the
                // constraint that reports it (XIV-114). Both are values the
                // container would otherwise try to autowire a `?string` into.
                __DIR__ . '/../src/Phone/{PhoneReading,DiallablePhoneNumber}.php',
                __DIR__ . '/../src/Metadata/{NumberingPlan,ConversionPlan}.php',
                __DIR__ . '/../src/Numbering/{NumberFormat,NumbersFound}.php',
                __DIR__ . '/../src/Record/{Record,Derivation}.php',
                __DIR__ . '/../src/Validation/UniqueFieldValue.php',
                // One value drawn as a chip, and the three answers a shared
                // list's own screens are made of ([XIV-127]). Values, not
                // services: the container would otherwise try to autowire a
                // `?ValueTone` and a `string $from` into them.
                __DIR__ . '/../src/Field/ValueBadge.php',
                __DIR__ . '/../src/ValueList/{MergeCount,MergePlan,ValueListUse}.php',
                __DIR__ . '/../src/XiviCoreBundle.php',
            ]);
};
