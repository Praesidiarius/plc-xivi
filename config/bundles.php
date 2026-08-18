<?php

$bundles = [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class => ['dev' => true],
    Symfony\Bundle\MakerBundle\MakerBundle::class => ['dev' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true],
    Sensiolabs\GotenbergBundle\SensiolabsGotenbergBundle::class => ['all' => true],
    Xivi\Core\XiviCoreBundle::class => ['all' => true],
    Xivi\Contact\XiviContactBundle::class => ['all' => true],
    Xivi\Article\XiviArticleBundle::class => ['all' => true],
    Xivi\Order\XiviOrderBundle::class => ['all' => true],
    Xivi\Invoice\XiviInvoiceBundle::class => ['all' => true],
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    Symfony\UX\LiveComponent\LiveComponentBundle::class => ['all' => true],
    Symfony\UX\Autocomplete\AutocompleteBundle::class => ['all' => true],
];

/*
 * **The administration surface, if this build has one** (XIV-96,
 * docs/architecture.md §4.4).
 *
 * Every other line in this file is unconditional because every other bundle is
 * in every image. This one is the seam the customer-facing build is cut along:
 * `Dockerfile`'s `frankenphp_public` target removes `packages/control-plane`
 * outright, so in that image the class below does not exist and
 * `Kernel::registerBundles()` would fatal on `new $class()` before anything
 * else got a chance to fail.
 *
 * **A `class_exists()` rather than a `%env()%` or an `if` on the environment**,
 * and the difference is the whole point of the ticket. A flag would mean the
 * administration code is present and switched off, which is one misconfiguration
 * away from being served; this asks whether it is *in the image*, and the answer
 * in a public build is no because the files are gone. [XIV-56] is the live
 * precedent for why that distinction is worth a build: something shipped inside
 * the production image that was never meant to be there, and no amount of
 * configuration would have kept it out.
 *
 * The autoloader is classmap-authoritative in a production build, so this is one
 * array lookup and no filesystem access — and it cannot answer "yes" for a class
 * whose file is not there, which is exactly the question being asked.
 *
 * It is appended rather than kept in its old position between core and the
 * modules. Bundle order decides which bundle's `prepend()` runs first and which
 * one's templates win an override, and this package neither overrides anything
 * nor prepends anything another bundle also prepends — see
 * XiviControlPlaneBundle::prependExtension(), which contributes the firewalls and
 * the entity mapping the application used to declare on its behalf.
 */
if (class_exists(Xivi\ControlPlane\XiviControlPlaneBundle::class)) {
    $bundles[Xivi\ControlPlane\XiviControlPlaneBundle::class] = ['all' => true];
}

return $bundles;
