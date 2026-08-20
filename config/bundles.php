<?php

/*
 * **This file is generated. If this comment is not here, a Symfony Flex recipe
 * rewrote the file and nothing is wrong** (XIV-111, docs/architecture/deployment.md §4.4).
 *
 * Flex regenerates `bundles.php` from its own template whenever a package is
 * added or removed, so anything hand-written in it is collateral by design.
 * That is why there is no logic below: every line is a declaration, and a
 * regeneration that produced exactly this array would leave the repository
 * working.
 *
 * One of the bundles below is **not in every image** — `packages/control-plane`
 * is removed from the customer-facing build — and the rule that lets this file
 * name it unconditionally lives in `config/optional_bundles.php` and
 * `App\Kernel`. Read that file before touching this one; it is where the
 * reasoning went when [XIV-111] took it out of here.
 */

return [
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
    Xivi\Voucher\XiviVoucherBundle::class => ['all' => true],
    Xivi\Knowledge\XiviKnowledgeBundle::class => ['all' => true],
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    Symfony\UX\LiveComponent\LiveComponentBundle::class => ['all' => true],
    Symfony\UX\Autocomplete\AutocompleteBundle::class => ['all' => true],
    Xivi\ControlPlane\XiviControlPlaneBundle::class => ['all' => true],
    Symfony\UX\Chartjs\ChartjsBundle::class => ['all' => true],
    League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
];
