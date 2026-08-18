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
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use DoctrineMigrations\Tenant\Version20260818150001;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\NumberingChange;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Numbering\NumberFormat;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\UniqueIndex;

/**
 * What the customer sees when the index refuses, and what the editor says before
 * it can (XIV-109).
 *
 * ### The refusal has to arrive as a form, not as a stack trace
 *
 * {@see UniqueValueRaceTest} proves the database refuses a duplicate and that
 * the engine turns that into {@see \Xivi\Core\Record\DuplicateValue}. This class
 * takes the other half: somebody pressing Save gets their form back, with a
 * message on the field, and nothing written — because a correctness fix that
 * shows a 500 to the person who did nothing wrong has moved the failure rather
 * than fixed it.
 *
 * ### Why it can be arranged without two connections
 *
 * A **derived** field is the one place the validator structurally cannot help.
 * Its value is not submitted and not validated: it is worked out inside the
 * writer's transaction, after everything has been checked, by
 * {@see \Xivi\Core\Numbering\AssignsNumbers}. So a document number that collides
 * reaches the index without any race at all, which makes it the honest and
 * repeatable way to exercise the path — and it is not a contrivance, it is
 * exactly the case §5.10 spent XIV-91 worrying about: a value sitting in the
 * column that no counter has ever heard of.
 *
 * The row carrying that value is written straight through the connection here,
 * which is what the ticket's own scenario is: something that got into the column
 * without going through the engine.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UniqueValueRefusalTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_unique_refusal';
    private const string HOST = 'unique.localhost';
    private const string EMAIL = 'unique@example.test';
    private const string PASSWORD = 'unique-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_HOST', self::HOST);

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $this->inTenant(fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));

        self::service(UserCreator::class)->create(
            $this->tenant,
            self::EMAIL,
            'Unique',
            self::PASSWORD,
            ['ROLE_ADMIN'],
        );

        $this->signIn();
    }

    private function signIn(): void
    {
        $page = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
        $this->client->submit($page->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
    }

    /**
     * The whole point, end to end: the index refuses and the customer gets a
     * sentence.
     *
     * A number the counter is about to hand out is already sitting on a record,
     * put there by something that did not go through the engine. Nothing
     * validates a derived field, so the save reaches the index — and comes back
     * as the form it was submitted from, with the message on the field.
     */
    public function testADuplicateTheValidatorCannotSeeComesBackAsAFormError(): void
    {
        $field = $this->aNumberedReferenceField();

        // One saved normally, so the counter is somewhere real rather than at
        // its first value.
        $this->aContact('First AG');
        self::assertSame('RE-0001', $this->referenceOf('First AG'));

        // And the row that slipped in: the value the counter will produce next,
        // written without the engine's knowledge.
        $this->writeARowCarrying('RE-0002');

        $response = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Second AG',
        ], variant: ContactModule::COMPANY);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), 'the form comes back rather than a redirect');
        self::assertSame('', (string) $response->headers->get('Location'), 'nothing was saved');

        $body = (string) $response->getContent();
        self::assertStringContainsString('was given this', $body, 'and it says what happened');
        self::assertStringContainsString($this->referenceLabel(), $body, 'naming the field');

        self::assertSame(2, $this->contactsCalled(), 'the first one and the row that slipped in, and no third');
    }

    /**
     * And the ordinary duplicate is still caught by the validator, on the field,
     * before anything is written.
     *
     * The layering said out loud: the index is what is *true* and the validator
     * is what is *readable*. If the index had replaced it, every duplicate would
     * arrive as a failed write and the message would stop being able to say
     * which value was rejected while the form was still open.
     */
    public function testAnOrdinaryDuplicateIsStillARefusalFromTheValidator(): void
    {
        $taken = 'ada@example.com';

        $first = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::PERSON,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => $taken,
        ], variant: ContactModule::PERSON);
        self::assertNotSame('', (string) $first->headers->get('Location'), 'the first one saves');

        $second = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::PERSON,
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => $taken,
        ], variant: ContactModule::PERSON);

        self::assertSame('', (string) $second->headers->get('Location'), 'the second one does not');
        self::assertStringContainsString(
            'Another record already uses this value.',
            (string) $second->getContent(),
            'in the words the validator has always used',
        );
    }

    /**
     * Marking a field unique is refused when records already share a value, and
     * the refusal names the values.
     *
     * The decision XIV-109 had to make about existing duplicates: refuse, rather
     * than apply the rule and leave records nobody can save. What makes the
     * refusal a step forward rather than a wall is the second half — a count on
     * its own leaves somebody scrolling a list looking for records they cannot
     * describe, and these are the search terms.
     */
    public function testMakingAFieldUniqueIsRefusedWithTheValuesToGoAndFix(): void
    {
        $field = $this->aPlainReferenceField();

        $this->aContact('Alpha AG', 'RE-7');
        $this->aContact('Beta AG', 'RE-7');
        $this->aContact('Gamma AG', 'RE-9');
        $this->aContact('Delta AG', 'RE-9');

        try {
            $this->withReference(fn ($field) => self::service(MetadataEditor::class)->makeUnique($field));

            self::fail('a column holding two pairs of duplicates was made unique');
        } catch (MetadataChangeRefused $refused) {
            self::assertStringContainsString('4 existing records', $refused->getMessage());
            self::assertStringContainsString('"RE-7"', $refused->getMessage(), 'the values, not only the count');
            self::assertStringContainsString('"RE-9"', $refused->getMessage());
        }

        self::assertFalse($this->referenceIsUnique(), 'and the flag did not move');
        self::assertNull($this->indexOn($field), 'nor was an index left behind');
    }

    /**
     * The index follows the flag in both directions.
     *
     * The claim the whole change rests on: a customer ticking the box gets an
     * index, unticking it takes the index away, and removing the field takes it
     * away too — because an index enforcing a rule no definition mentions is a
     * refusal nothing on the screen can explain.
     */
    public function testTheIndexIsCreatedAndDroppedWithTheFlag(): void
    {
        $field = $this->aPlainReferenceField();

        self::assertNull($this->indexOn($field), 'an ordinary field has none');

        $this->withReference(fn ($field) => self::service(MetadataEditor::class)->makeUnique($field));
        self::assertNotNull($this->indexOn($field), 'ticking the box builds one');

        $this->updateReference(unique: false);
        self::assertNull($this->indexOn($field), 'unticking it takes it away');

        $this->updateReference(unique: true);
        self::assertNotNull($this->indexOn($field), 'and back');

        $this->withReference(fn ($field) => self::service(MetadataEditor::class)->removeField($field));
        self::assertNull($this->indexOn($field), 'removing the field takes the rule with it');
    }

    /**
     * Turning numbering on marks the field unique, which is what closes §5.10's
     * window.
     *
     * A document number nobody else may carry is what a document number *is*, so
     * the flag goes on with the pattern and the index goes on with the flag. The
     * window XIV-91 wrote down — the milliseconds between the column scan and the
     * commit, during which a save on another connection could type a value in
     * beside the floor — is closed by the table lock that build takes, and the
     * duplicate it was worried about is refused for ever afterwards.
     */
    public function testANumberedFieldIsAUniqueField(): void
    {
        $field = $this->aNumberedReferenceField();

        self::assertTrue($this->referenceIsUnique(), 'numbering implies it');
        self::assertTrue($this->referenceIsDerived(), 'beside the flag XIV-91 set');
        self::assertNotNull($this->indexOn($field), 'and the index is there');
    }

    /** Un-numbering leaves it unique, because the numbers already handed out are still out there. */
    public function testStoppingNumberingKeepsTheFieldUnique(): void
    {
        $field = $this->aNumberedReferenceField();

        $this->withReference(fn ($field) => self::service(NumberingChange::class)->stop($field));

        self::assertFalse($this->referenceIsDerived(), 'typeable again');
        self::assertTrue($this->referenceIsUnique(), 'and still unique');
        self::assertNotNull($this->indexOn($field), 'so nobody can type a number that is already on a document');
    }

    /**
     * A tenant that predates this release gets the indexes its own definitions
     * imply, without anybody editing a field (XIV-109).
     *
     * The migration is the only part of this change that existing customers meet,
     * and the suite provisions fresh databases whose `field_definition` table is
     * empty at migration time — so run as it normally is, it proves nothing. This
     * puts a tenant back into the state the release finds them in (indexes gone,
     * a numbered field not marked unique) and runs the migration object against
     * it, statement for statement.
     *
     * Reading `is_unique` back through SQL rather than through the metadata
     * repository, because the migration writes behind the entity manager's back —
     * as a migration must — and the question here is what is in the table.
     */
    public function testTheMigrationGivesAnExistingTenantTheIndexesItsDefinitionsImply(): void
    {
        $reference = $this->aNumberedReferenceField();
        $email = $this->inTenant(fn (): string => UniqueIndex::nameFor(
            self::service(MetadataRepository::class)->get(ContactModule::KEY)->getTableName(),
            'email',
        ));

        // Back to how a customer's database looks on the release before this one:
        // the flag they ticked by hand is still ticked, the numbered field is not
        // unique because nothing ever said it should be, and nothing is indexed.
        $this->inTenant(function () use ($reference, $email): void {
            $connection = $this->tenantConnection();
            $connection->executeStatement(sprintf('DROP INDEX %s', $reference));
            $connection->executeStatement(sprintf('DROP INDEX %s', $email));
            $connection->executeStatement("UPDATE field_definition SET is_unique = FALSE WHERE field_key = 'reference'");
        });

        self::assertNull($this->indexOn($reference), 'the tenant starts with none');
        self::assertNull($this->indexOn($email));

        $this->runTheMigration();

        self::assertNotNull($this->indexOn($email), 'the field the customer marked unique');
        self::assertNotNull($this->indexOn($reference), 'and the one numbering made unique');
        self::assertTrue($this->uniqueFlagOf('reference'), 'whose definition now says so');
    }

    /** And running it twice changes nothing, because a deploy can be replayed. */
    public function testTheMigrationCanBeRunTwice(): void
    {
        $this->aNumberedReferenceField();

        $this->runTheMigration();
        $this->runTheMigration();

        self::assertTrue($this->uniqueFlagOf('reference'));
    }

    // -- helpers ------------------------------------------------------------

    /**
     * The migration's own `up()`, executed against this tenant.
     *
     * The class rather than `tenant:migrate`, because Doctrine records what it
     * has already run against this database — and what is under test is the SQL,
     * not the bookkeeping around it.
     */
    private function runTheMigration(): void
    {
        $this->inTenant(function (): void {
            $connection = $this->tenantConnection();
            $migration = new Version20260818150001($connection, new NullLogger());
            $migration->up($connection->createSchemaManager()->introspectSchema());

            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
    }

    private function uniqueFlagOf(string $key): bool
    {
        return (bool) $this->inTenant(fn () => $this->tenantConnection()->fetchOne(
            'SELECT is_unique FROM field_definition WHERE field_key = :key',
            ['key' => $key],
        ));
    }

    /** A plain text field of the customer's own, the way they add one. */
    private function aPlainReferenceField(): string
    {
        $this->inTenant(function (): void {
            $contact = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            if ($contact->getField('reference') === null) {
                self::service(MetadataEditor::class)->addField(
                    shape: $contact,
                    key: 'reference',
                    label: 'Reference',
                    type: 'text',
                );
            }
        });

        return $this->inTenant(fn (): string => UniqueIndex::nameFor(
            self::service(MetadataRepository::class)->get(ContactModule::KEY)->getTableName(),
            'reference',
        ));
    }

    /** And the same field with numbering turned on, which is XIV-91's whole act. */
    private function aNumberedReferenceField(): string
    {
        $index = $this->aPlainReferenceField();

        $this->inTenant(function (): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $field = $module->getField('reference');
            self::assertNotNull($field);

            $format = NumberFormat::parse('RE-{number:4}');
            \assert($format !== null);

            self::service(NumberingChange::class)->start($module, $field, $format, new \DateTimeImmutable());
        });

        return $index;
    }

    private function updateReference(bool $unique): void
    {
        $this->withReference(function ($field) use ($unique): void {
            self::service(MetadataEditor::class)->updateField(
                field: $field,
                label: $field->getLabel(),
                required: $field->isRequired(),
                unique: $unique,
                filterable: $field->isFilterable(),
                listed: $field->isListed(),
                title: $field->isTitle(),
                position: $field->getPosition(),
            );
        });
    }

    /**
     * The reference field, resolved and used inside **one** tenant context.
     *
     * Not a getter returning the entity, deliberately, and the reason is §7.4's
     * rather than style: {@see TenantSwitcher} drops the identity map on every
     * switch, so a definition fetched in one `runFor` and handed to a writer in
     * the next is a *detached* entity — `flush()` ignores it and `remove()`
     * throws. Passing the work in instead makes that unrepresentable.
     *
     * @template T
     *
     * @param callable(\Xivi\Core\Entity\FieldDefinition):T $work
     *
     * @return T
     */
    private function withReference(callable $work): mixed
    {
        return $this->inTenant(function () use ($work): mixed {
            $field = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField('reference');
            self::assertNotNull($field, 'the contact module has a "reference" field');

            return $work($field);
        });
    }

    /** What the definition says now, read rather than carried out of its context. */
    private function referenceIsUnique(): bool
    {
        return $this->withReference(static fn ($field): bool => $field->isUnique());
    }

    private function referenceIsDerived(): bool
    {
        return $this->withReference(static fn ($field): bool => $field->isDerived());
    }

    private function referenceLabel(): string
    {
        return $this->withReference(static fn ($field): string => $field->getLabel());
    }

    /** The index by name, or null — asked of Postgres rather than of the engine. */
    private function indexOn(string $name): ?string
    {
        $found = $this->inTenant(fn () => $this->tenantConnection()->fetchOne(
            'SELECT indexdef FROM pg_indexes WHERE indexname = :name',
            ['name' => $name],
        ));

        return \is_string($found) ? $found : null;
    }

    /** A company saved the way somebody saves one, through the record form. */
    private function aContact(string $name, ?string $reference = null): void
    {
        $fields = ['kind' => ContactModule::COMPANY, 'company_name' => $name];

        if ($reference !== null) {
            $fields['reference'] = $reference;
        }

        $this->savedId($this->saveRecord(ContactModule::KEY, $fields, variant: ContactModule::COMPANY));
    }

    /**
     * A row put into the table by something that is not the engine.
     *
     * Which is the whole scenario: a value in a numbered column that no counter
     * has heard of. Straight SQL, because every route through the engine would
     * either refuse it or renumber it.
     */
    private function writeARowCarrying(string $reference): void
    {
        $this->inTenant(fn () => $this->tenantConnection()->executeStatement(
            'INSERT INTO contact (created_at, updated_at, data) VALUES (NOW(), NOW(), CAST(:data AS jsonb))',
            ['data' => json_encode([
                'kind' => ContactModule::COMPANY,
                'company_name' => 'Slipped In AG',
                'reference' => $reference,
            ], \JSON_THROW_ON_ERROR)],
        ));
    }

    private function referenceOf(string $companyName): string
    {
        return $this->inTenant(function () use ($companyName): string {
            $rows = $this->tenantConnection()->fetchOne(
                "SELECT data->>'reference' FROM contact WHERE data->>'company_name' = :name",
                ['name' => $companyName],
            );

            return \is_string($rows) ? $rows : '';
        });
    }

    private function contactsCalled(): int
    {
        return (int) $this->inTenant(fn () => $this->tenantConnection()
            ->fetchOne('SELECT COUNT(*) FROM contact WHERE deleted_at IS NULL'));
    }

    /**
     * The connection a request to this customer is served on.
     *
     * By service id rather than by class: `Connection::class` is the control
     * plane's, and the tenant one is a second connection the application points
     * at whoever is being served.
     */
    private function tenantConnection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    private function inTenant(callable $work): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, $work);
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
