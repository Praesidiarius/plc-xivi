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

namespace App\Tests\Functional\ControlPlane;

use App\Registry\Catalog\CatalogEntry;
use App\Registry\Catalog\ModuleCatalog;
use App\Registry\Entity\Module;
use App\Registry\Entity\ModuleState;
use App\Registry\Repository\ModuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * How far along a module is, and who gets to say so (XIV-7).
 *
 * Writes to the control plane, which DAMA deliberately does not wrap in a
 * transaction (config/packages/test/dama_doctrine_test.yaml) — so this class
 * clears up after itself rather than being rolled back.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleStateTest extends KernelTestCase
{
    /**
     * In the build, so the catalogue will accept decisions about it.
     *
     * Article rather than contact, and deliberately: module state lives in the
     * *control plane*, which DAMA does not roll back and which every paratest
     * worker shares. Two classes publishing the same module at once is a race
     * whose failure lands in whichever of them lost, so the classes that write
     * state keep to different keys — {@see \App\Tests\Functional\Tenant\ModuleStoreTest}
     * has contact, order and invoice, and leaves this one alone.
     */
    private const string MODULE = 'article';

    /** Deliberately not in any build: a decision that outlived its code. */
    private const string GHOST = 'test_ghost_module';

    private ModuleCatalog $catalog;
    private ModuleRepository $modules;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $catalog = $container->get(ModuleCatalog::class);
        \assert($catalog instanceof ModuleCatalog);
        $this->catalog = $catalog;

        $modules = $container->get(ModuleRepository::class);
        \assert($modules instanceof ModuleRepository);
        $this->modules = $modules;

        $entityManager = $container->get('doctrine.orm.control_entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $this->forgetDecisions();
    }

    protected function tearDown(): void
    {
        $this->forgetDecisions();

        parent::tearDown();
    }

    public function testAModuleNobodyHasDecidedAboutIsInDevelopment(): void
    {
        self::assertNull($this->modules->findOneByKey(self::MODULE), 'no row, on purpose');
        self::assertSame(ModuleState::Development, $this->catalog->state(self::MODULE));
        self::assertArrayNotHasKey(self::MODULE, $this->catalog->offeredInStore());
    }

    public function testPublishingOffersItToEveryTenant(): void
    {
        $this->catalog->moveTo(self::MODULE, ModuleState::Published);

        self::assertSame(ModuleState::Published, $this->catalog->state(self::MODULE));
        self::assertArrayHasKey(self::MODULE, $this->catalog->offeredInStore());
    }

    /** Pulling it back leaves the row: the state changed, the decision still happened. */
    public function testItCanBeTakenBackOutOfTheStore(): void
    {
        $this->catalog->moveTo(self::MODULE, ModuleState::Published);
        $this->catalog->moveTo(self::MODULE, ModuleState::Development);

        self::assertArrayNotHasKey(self::MODULE, $this->catalog->offeredInStore());
        self::assertNotNull($this->modules->findOneByKey(self::MODULE));
    }

    public function testAModuleThisBuildDoesNotShipCannotBeGivenAState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No module "test_ghost_module" in this build');

        $this->catalog->moveTo(self::GHOST, ModuleState::Published);
    }

    /**
     * A row whose module has left the build is listed and flagged, never offered:
     * the store cannot install what the deploy does not carry.
     */
    public function testADecisionThatOutlivedItsCodeIsListedButNotOffered(): void
    {
        $this->entityManager->persist(new Module(self::GHOST, ModuleState::Published));
        $this->entityManager->flush();

        $entries = $this->catalog->entries();
        $keys = array_map(static fn (CatalogEntry $e): string => $e->key, $entries);

        self::assertContains(self::GHOST, $keys);
        self::assertContains(self::MODULE, $keys, 'the build half of the catalogue is still there');

        $ghost = array_values(array_filter(
            $entries,
            static fn (CatalogEntry $e): bool => $e->key === self::GHOST,
        ))[0];

        self::assertFalse($ghost->isInBuild());
        self::assertSame(ModuleState::Published, $ghost->state);
        self::assertArrayNotHasKey(self::GHOST, $this->catalog->offeredInStore());
    }

    /** The process the acceptance criteria asked for, driven the way an operator would. */
    public function testTheCommandMovesAModuleBetweenStates(): void
    {
        $tester = $this->command();

        $tester->execute(['module' => self::MODULE, 'state' => 'published']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('development to published', $tester->getDisplay());
        self::assertSame(ModuleState::Published, $this->freshState());

        // Said again, rather than silently written twice.
        $tester->execute(['module' => self::MODULE, 'state' => 'published']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already published', $tester->getDisplay());
    }

    public function testTheCommandRefusesAModuleThisBuildDoesNotShip(): void
    {
        $tester = $this->command();

        $tester->execute(['module' => self::GHOST, 'state' => 'published']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No module "test_ghost_module"', $tester->getDisplay());
    }

    private function command(): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        return new CommandTester((new Application($kernel))->find('module:state'));
    }

    /** Read past the identity map, so the assertion is about the database. */
    private function freshState(): ModuleState
    {
        $this->entityManager->clear();

        return $this->catalog->state(self::MODULE);
    }

    private function forgetDecisions(): void
    {
        $this->entityManager->createQuery(
            'DELETE FROM ' . Module::class . ' m WHERE m.key IN (:keys)'
        )
            ->setParameter('keys', [self::MODULE, self::GHOST])
            ->execute();

        $this->entityManager->clear();
    }
}
