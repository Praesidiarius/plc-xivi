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
                // A record, what a deriver made of one, and what a module handed
                // back to draw its own index with (XIV-178). The last is an
                // answer rather than a service: the container would otherwise
                // try to autowire a `string $template` into it.
                __DIR__ . '/../src/Record/{Record,Derivation,IndexBody}.php',
                __DIR__ . '/../src/Validation/UniqueFieldValue.php',
                // One thing the clock owes a tenant, and what a turn of it came
                // to (XIV-155). Values the runner builds and hands back, not
                // services: the container would otherwise try to autowire a
                // `string $subject` into the first and register the second as
                // something nobody can call anything on.
                __DIR__ . '/../src/Schedule/{Occurrence,WorkReport}.php',
                // One value drawn as a chip, and the three answers a shared
                // list's own screens are made of ([XIV-127]). Values, not
                // services: the container would otherwise try to autowire a
                // `?ValueTone` and a `string $from` into them.
                __DIR__ . '/../src/Field/ValueBadge.php',
                // What a record holds when it holds a file, and how large a file
                // may be ([XIV-115]). A value and a pair of constants: the
                // container would otherwise try to autowire a `string $token`
                // into the first and register the second as a service nobody can
                // call anything on.
                __DIR__ . '/../src/Field/{StoredFile,AttachmentLimit}.php',
                __DIR__ . '/../src/ValueList/{MergeCount,MergePlan,ValueListUse}.php',
                __DIR__ . '/../src/XiviCoreBundle.php',
            ]);
};
