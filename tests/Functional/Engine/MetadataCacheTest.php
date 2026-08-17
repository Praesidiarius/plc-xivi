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

namespace App\Tests\Functional\Engine;

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * Definitions are read once per tenant, and never the wrong tenant's (XIV-53).
 *
 * The optimisation is easy and the hazard is the whole reason it had not been
 * done: a cache of one customer's field definitions served to another is the
 * worst bug this system could have, and it would not look like a bug — it would
 * look like the wrong labels on somebody else's data (§7.4).
 *
 * A web request is a process, so nothing survives one; a console command walking
 * every tenant is not, and that is what these tests are about.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MetadataCacheTest extends KernelTestCase
{
    use SharesATenant;

    private const string ALPHA = 'test_meta_alpha';
    private const string BETA = 'test_meta_beta';

    private TenantSwitcher $switcher;
    private Tenant $alpha;
    private Tenant $beta;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);

        $this->alpha = $this->sharedTenant(self::ALPHA, ['meta-alpha.localhost']);
        $this->beta = $this->sharedTenant(self::BETA, ['meta-beta.localhost']);

        foreach ([$this->alpha, $this->beta] as $tenant) {
            $this->switcher->runFor($tenant, fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            ));
        }
    }

    /**
     * The point of the cache: the same shape twice costs one read.
     *
     * Asserted on the object rather than by counting queries — two calls giving
     * back the *same instance* is what "read once" means, and it cannot be true
     * by accident.
     */
    public function testAShapeIsReadOncePerTenant(): void
    {
        [$first, $second] = $this->switcher->runFor($this->alpha, fn (): array => [
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
        ]);

        self::assertSame($first, $second, 'the second ask did not go back to the database');
    }

    /**
     * **The hazard.** Two tenants in one process, which is what a console command
     * is, and each gets its own labels.
     *
     * Renaming the field in one tenant makes the two answers distinguishable: if
     * anything survived the switch, the second tenant reads the first's name for
     * a field of its own.
     */
    public function testOneTenantNeverReadsAnothersDefinitions(): void
    {
        $this->switcher->runFor($this->alpha, function (): void {
            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $field = $contacts->getField('first_name');
            self::assertNotNull($field);

            self::service(MetadataEditor::class)->updateField(
                field: $field,
                label: 'Alpha calls this something else',
                required: $field->isRequired(),
                unique: $field->isUnique(),
                filterable: $field->isFilterable(),
                listed: $field->isListed(),
                title: $field->isTitle(),
                position: $field->getPosition(),
            );
        });

        $labels = [];

        // In sequence, in one process — a tenant:migrate in miniature.
        foreach (['alpha' => $this->alpha, 'beta' => $this->beta] as $name => $tenant) {
            $labels[$name] = $this->switcher->runFor($tenant, function (): string {
                $field = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField('first_name');
                self::assertNotNull($field);

                return $field->getLabel();
            });
        }

        self::assertSame('Alpha calls this something else', $labels['alpha']);
        self::assertNotSame(
            $labels['alpha'],
            $labels['beta'],
            'the second tenant read its own definitions, not the first tenant\'s',
        );
    }

    /**
     * And a definition changed mid-request is visible immediately.
     *
     * A cache that outlived a write would make the metadata editor look broken:
     * somebody renames a field, the page redraws, and the old name is still
     * there.
     */
    public function testAChangeIsVisibleWithinTheRequestThatMadeIt(): void
    {
        $label = $this->switcher->runFor($this->alpha, function (): string {
            $repository = self::service(MetadataRepository::class);

            $field = $repository->get(ContactModule::KEY)->getField('first_name');
            self::assertNotNull($field);

            self::service(MetadataEditor::class)->updateField(
                field: $field,
                label: 'Renamed just now',
                required: $field->isRequired(),
                unique: $field->isUnique(),
                filterable: $field->isFilterable(),
                listed: $field->isListed(),
                title: $field->isTitle(),
                position: $field->getPosition(),
            );

            $again = $repository->get(ContactModule::KEY)->getField('first_name');
            self::assertNotNull($again);

            return $again->getLabel();
        });

        self::assertSame('Renamed just now', $label);
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
