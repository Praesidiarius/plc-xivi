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

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Introspection\TenantInspector;
use App\ControlPlane\Introspection\UnknownTenant;
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * Reading an installation as it actually is (XIV-76).
 *
 * **The claim under test is the one §6.1 makes**, and it is the reason the MCP
 * tools exist at all: once a module is installed, the customer's definitions are
 * the truth and the blueprint in `packages/contact` is only what they started
 * from. So the discriminating assertion here is not that the inspector lists a
 * contact's fields — it is that it lists a field the contact *module has never
 * heard of*, added in the editor the way a customer would. Anything reading the
 * blueprint would pass every other test in this class and fail that one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantInspectionTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_inspection';
    private const string HOST = 'inspection.localhost';

    /** The customer's own field, which no blueprint declares. */
    private const string LOYALTY = 'loyalty_number';

    private Tenant $tenant;
    private TenantInspector $inspector;

    protected function setUp(): void
    {
        // SymfonyStyle wraps its tables to the terminal, and the assertions below
        // read cell values out of that output — see TenantDeprovisionCommandTest
        // for the same pin and the same reason.
        putenv('COLUMNS=240');

        self::bootKernel();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $inspector = self::getContainer()->get(TenantInspector::class);
        \assert($inspector instanceof TenantInspector);
        $this->inspector = $inspector;

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            $contact = $installer->install($registry->get(ContactModule::KEY));

            if ($contact->getField(self::LOYALTY) === null) {
                self::service(MetadataEditor::class)->addField(
                    $contact,
                    self::LOYALTY,
                    'Loyalty number',
                    'text',
                    filterable: true,
                    options: ['max_length' => 12],
                );
            }
        });
    }

    protected function tearDown(): void
    {
        putenv('COLUMNS');

        parent::tearDown();
    }

    /**
     * The whole point: a field the module never declared, read back with its
     * type and its options intact.
     */
    public function testShapesReportTheTenantsOwnFieldsRatherThanTheBlueprints(): void
    {
        $shapes = $this->inspector->shapes(self::SLUG, ContactModule::KEY);

        self::assertCount(1, $shapes);

        $fields = self::keyed($shapes[0]['fields']);

        self::assertArrayHasKey(self::LOYALTY, $fields, 'a customer field must appear');
        self::assertSame('text', $fields[self::LOYALTY]['type']);
        self::assertSame(['max_length' => 12], $fields[self::LOYALTY]['options']);
        self::assertTrue($fields[self::LOYALTY]['filterable']);
        // Not the module's, so not protected from removal — the flag a caller
        // needs before offering to delete anything (§7.2).
        self::assertFalse($fields[self::LOYALTY]['system']);

        // And the module's own fields are still there, still flagged as its own.
        self::assertArrayHasKey('email', $fields);
        self::assertTrue($fields['email']['system']);
    }

    /**
     * Variants and collections are part of a shape, so a description that stopped
     * at the top-level fields would be describing a third of it (§5.1, §5.5).
     */
    public function testShapesCarryVariantsAndCollections(): void
    {
        $contact = $this->inspector->shapes(self::SLUG, ContactModule::KEY)[0];

        self::assertSame('kind', $contact['variant_field']);
        self::assertSame(['person', 'company'], array_keys($contact['variants']));

        $collections = self::keyed($contact['collections']);
        self::assertArrayHasKey('addresses', $collections);
        self::assertNotEmpty($collections['addresses']['fields']);

        // Scoped to one variant, which is the fact that makes a first name absent
        // from a company rather than merely optional on one.
        $fields = self::keyed($contact['fields']);
        self::assertSame(['person'], $fields['first_name']['variants']);
        self::assertSame([], $fields['email']['variants']);
    }

    /** A module this tenant has not installed is not a shape it has. */
    public function testShapesAreEmptyForAModuleTheTenantHasNotInstalled(): void
    {
        self::assertSame([], $this->inspector->shapes(self::SLUG, 'invoice'));
    }

    /** The correction is the list, so the next guess is not needed. */
    public function testAnUnknownSlugNamesTheSlugsThatExist(): void
    {
        $this->expectException(UnknownTenant::class);
        $this->expectExceptionMessageMatches('/No tenant with slug "nope"\. Provisioned: .*' . self::SLUG . '/');

        $this->inspector->shapes('nope');
    }

    /**
     * The registry half, plus the two facts about a tenant that need its own
     * database opened: whether its schema is current, and what it has installed.
     */
    public function testTenantsReportSchemaCurrencyAndInstalledModules(): void
    {
        $row = $this->inspector->tenant(self::SLUG);

        self::assertNotNull($row);
        self::assertSame(self::SLUG, $row['slug']);
        self::assertContains(self::HOST, $row['hostnames']);
        // The database and the role, and never the DSN — it carries a credential.
        self::assertStringContainsString(self::SLUG, (string) $row['database']);
        self::assertArrayNotHasKey('dsn', $row);

        self::assertIsArray($row['schema']);
        // Provisioning migrates to latest, so a tenant this test just made is by
        // definition current. The assertion is worth making anyway: it is the
        // only thing that would notice `status()` reporting against the control
        // plane's migrations instead of the tenant's.
        self::assertTrue($row['schema']['up_to_date']);
        self::assertSame([], $row['schema']['pending']);
        self::assertNotNull($row['schema']['current']);

        self::assertContains(ContactModule::KEY, $row['modules']);
    }

    /** The cheap question, and it says which one it answered. */
    public function testTheShallowReadSkipsTheDatabaseAndSaysSo(): void
    {
        $row = $this->inspector->tenant(self::SLUG, deep: false);

        self::assertNotNull($row);
        self::assertArrayNotHasKey('schema', $row);
        self::assertArrayNotHasKey('modules', $row);
    }

    /** Both halves of §6.2, joined: what the build ships and what state it is in. */
    public function testTheModuleCatalogueCarriesStateAndRequirements(): void
    {
        $modules = self::keyed($this->inspector->modules());

        self::assertArrayHasKey('contact', $modules);
        self::assertTrue($modules['contact']['in_this_build']);
        self::assertContains($modules['contact']['state'], ['development', 'published']);

        // A runtime requirement, declared as a key rather than a code dependency
        // (§3, XIV-23) — and the thing a caller has to know before offering to
        // install anything.
        self::assertContains('contact', $modules['invoice']['requires']);
    }

    /**
     * **Nothing the tools expose may be tool-only.** This is the assertion that
     * keeps that promise honest: the same three answers, out of `bin/console`,
     * in the same structure the MCP tools return.
     */
    public function testTheConsoleCommandAnswersTheSameThreeQuestions(): void
    {
        $shapes = $this->decoded(['slug' => self::SLUG, 'module' => ContactModule::KEY, '--json' => true]);
        self::assertArrayHasKey(self::LOYALTY, self::keyed($shapes[0]['fields']));

        $tenants = self::keyed($this->decoded(['--json' => true]), 'slug');
        self::assertArrayHasKey(self::SLUG, $tenants);

        $modules = self::keyed($this->decoded(['--modules' => true, '--json' => true]));
        self::assertArrayHasKey('contact', $modules);
    }

    /** And it says so plainly rather than throwing at whoever mistyped a slug. */
    public function testTheConsoleCommandFailsCleanlyOnAnUnknownSlug(): void
    {
        $tester = $this->command();
        $tester->execute(['slug' => 'nope']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No tenant with slug "nope"', $tester->getDisplay());
    }

    /**
     * @param array<string, scalar|null> $input
     *
     * @return list<array<string, mixed>>
     */
    private function decoded(array $input): array
    {
        $tester = $this->command();
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        /** @var list<array<string, mixed>> $decoded */
        $decoded = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function command(): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        return new CommandTester((new Application($kernel))->find('tenant:inspect'));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private static function keyed(array $rows, string $by = 'key'): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(string) $row[$by]] = $row;
        }

        return $keyed;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
