<?php

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
];
