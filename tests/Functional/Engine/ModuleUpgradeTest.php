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
use App\Tests\Support\Module\GrownModule;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\UniqueIndex;

/**
 * A customer taking what their module grew after they installed it (XIV-70,
 * docs/architecture/open-questions.md §7.2.1).
 *
 * The tenant here is installed from a *reduced* blueprint — one field of a module
 * that has five and a collection — which is the honest way to produce the thing
 * this ticket is about: an installation older than the module. Everything then
 * comes from the registry, exactly as it would for a customer who installed
 * Contact before it grew an addresses collection.
 *
 * What is under test is mostly what does **not** happen. §6.1 says a customer's
 * definitions are the truth once a module is installed, and this feature is the
 * sanctioned exception to it rather than a repeal — so the claims worth making
 * are that a renamed field stays renamed, that a deliberately deleted one is not
 * offered back for ever, that no value is written into any record, and that a
 * rule the records could not keep arrives switched off instead of leaving
 * somebody a module they cannot save.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleUpgradeTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_module_upgrade';
    private const string HOST = 'upgrade.localhost';
    private const string EMAIL = 'upgrade@example.test';
    private const string ORDINARY = 'ordinary@example.test';
    private const string PASSWORD = 'upgrade-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_HOST', self::HOST);

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $this->inTenant(function (): void {
            // Yesterday's shape. The installer takes a blueprint rather than a
            // key precisely because it is a seed (§6.1), which is what makes an
            // out-of-date installation something a test can produce honestly
            // instead of by editing rows.
            self::service(ModuleInstaller::class)->install(self::reducedBlueprint());
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::EMAIL, 'Upgrade', self::PASSWORD, ['ROLE_ADMIN']);
        // Somebody who works here and is not an administrator. Taking what a
        // module grew changes what its records *are*, which is the metadata
        // editor's authority (§5.4) — so no grant they could be given makes any
        // difference, and they are given none.
        $users->create($this->tenant, self::ORDINARY, 'Ordinary', self::PASSWORD, []);

        $this->signIn();
    }

    /** The field editor says there is something to read, and nothing more. */
    public function testTheFieldEditorSaysWhatTheModuleHasGained(): void
    {
        $page = $this->client->request('GET', $this->url('/m/grown/fields'));

        self::assertStringContainsString('missing 5 things', $page->html());
        self::assertCount(1, $page->filter('a[href$="/m/grown/upgrade"]'));
    }

    /**
     * The offer is the difference between the blueprint and this copy.
     *
     * Including the collection, which is the addition a customer could never
     * make for themselves: it needs a table, and only the installer creates one
     * (§6.1).
     */
    public function testTheOfferIsWhatTheBlueprintHasAndThisCopyHasNot(): void
    {
        $offered = $this->offered();

        self::assertSame(
            ['field:owner', 'field:serial', 'field:notes', 'field:total', 'collection:parts'],
            $offered,
            'in the blueprint\'s own order, its fields before its collections',
        );
    }

    /**
     * The scale, named before anything is written ([XIV-91]'s shape).
     *
     * Nothing here destroys anything and it is confirmed anyway, because "a
     * table appears in your database and every record in this module gains four
     * fields" is a sentence somebody should read before it is true.
     */
    public function testTheReviewNamesTheScaleBeforeAnythingIsWritten(): void
    {
        $this->aRecord('First');
        $this->aRecord('Second');

        $review = $this->review($this->tokens());
        $html = $review->html();

        self::assertStringContainsString('All 2 records', $html, 'how many records are in scope');
        self::assertStringContainsString('One table is created', $html, 'and what happens to the database');
        self::assertStringContainsString('arrives optional here', $html, 'the rule the records could not keep');
        self::assertStringContainsString('filled in by the engine', $html, 'and the field nothing here fills in');
        self::assertStringContainsString('Nothing you already have is touched', $html);

        self::assertSame(['name'], $this->fieldKeys(), 'and the review has written nothing');
    }

    /**
     * The confirmation is required by the controller, not only by the template.
     *
     * A `required` attribute is a courtesy to somebody using the page and
     * nothing at all to a form posted around it, and on the other side of this
     * call is a table being created in a customer's database.
     */
    public function testTakingWithoutConfirmingDoesNothing(): void
    {
        $this->client->request('POST', $this->url('/m/grown/upgrade/take'), [
            '_token' => $this->token(),
            'additions' => $this->tokens(),
        ]);

        self::assertSame(['name'], $this->fieldKeys());
    }

    /**
     * Taking them adds what the blueprint declares and touches nothing else.
     *
     * The customisation is made *first* and asserted afterwards, which is the
     * claim §6.1 needs: a relabelled field with a width of its own is a decision
     * somebody made, and an upgrade that corrected it back towards the blueprint
     * would have quietly repealed the rule this whole feature is built on top
     * of.
     */
    public function testTakingAddsWhatIsMissingAndChangesNothingThatExists(): void
    {
        $this->inTenant(function (): void {
            $editor = self::service(MetadataEditor::class);
            $name = $this->field('name');

            $editor->updateField(
                field: $name,
                label: 'What we call it',
                required: true,
                unique: false,
                filterable: false,
                listed: true,
                title: true,
                position: 10,
                width: 4,
            );
        });

        $this->takeEverything();

        self::assertSame(
            ['name', 'owner', 'serial', 'notes', 'total'],
            $this->fieldKeys(),
            'appended in the blueprint\'s order, after the field that was already there',
        );

        $name = $this->inTenant(fn (): FieldDefinition => $this->field('name'));
        self::assertSame('What we call it', $name->getLabel(), 'their label survives');
        self::assertSame(4, $name->getWidth(), 'and so does their width');

        $module = $this->inTenant(fn (): ModuleDefinition => $this->module());
        self::assertSame(['parts'], $module->getCollectionKeys());
        self::assertTrue(
            $this->inTenant(fn (): bool => self::tableExists(GrownModule::COLLECTION_TABLE)),
            'a collection is a table, and only the installer makes one',
        );

        $parts = $module->getCollection(GrownModule::COLLECTION);
        self::assertNotNull($parts);
        self::assertSame(['label', 'amount'], $parts->getFieldKeys());
    }

    /**
     * A unique field taken through the upgrade gets the index behind the flag.
     *
     * **This test exists because of the merge, not because of either branch.**
     * [XIV-70] built the upgrade and [XIV-109] made `unique` a real index, and
     * the two were written in parallel worktrees that could not see each other.
     * Each was green alone. Together, `adoptField()` set the flag and built
     * nothing, which would have made the upgrade the one way into this engine
     * that promises uniqueness and leaves the old read-then-write race in place
     * — for precisely the fields a blueprint marked unique on purpose.
     *
     * Asserted against `pg_indexes` rather than by racing two saves, because the
     * race is already proved in `UniqueValueRaceTest` and what is in doubt here
     * is only whether this path reaches it.
     */
    public function testAUniqueFieldTakenThroughTheUpgradeIsIndexed(): void
    {
        $this->takeEverything();

        $definition = $this->inTenant(fn (): FieldDefinition => $this->field('serial'));
        self::assertTrue($definition->isUnique(), 'the blueprint marks it unique and the records can keep it');

        $module = $this->inTenant(fn (): ModuleDefinition => $this->module());
        $name = UniqueIndex::nameFor($module->getTableName(), 'serial');

        $definitionSql = $this->inTenant(fn (): mixed => self::service(Connection::class, 'doctrine.dbal.tenant_connection')
            ->fetchOne('SELECT indexdef FROM pg_indexes WHERE indexname = :name', ['name' => $name]));

        self::assertIsString($definitionSql, sprintf('no index %s — the flag is on and nothing enforces it', $name));
        self::assertStringContainsString('UNIQUE', $definitionSql);
    }

    /**
     * A rule the records could not keep arrives switched off; one they can keep
     * does not.
     *
     * Both halves matter. Installing `required` over records that are all empty
     * in it would leave a module nobody can save a record in, which is exactly
     * what §5.4 refuses to do to somebody. But relaxing every rule on the way in
     * would be a blanket answer to a question that is worth counting: two
     * records with nothing in a field are not duplicates of each other, so
     * `unique` survives.
     */
    public function testARuleTheRecordsCouldNotKeepArrivesSwitchedOff(): void
    {
        $this->aRecord('First');
        $this->aRecord('Second');

        $this->takeEverything();

        $owner = $this->inTenant(fn (): FieldDefinition => $this->field('owner'));
        self::assertTrue($owner->isSystem(), 'it is the module\'s own field, arriving late');
        self::assertFalse($owner->isRequired(), 'and optional, because two records could not satisfy it');

        self::assertTrue(
            $this->inTenant(fn (): bool => $this->field('serial')->isUnique()),
            'while unique survives, since nothing holds a value yet',
        );
    }

    /**
     * A field with no records to disagree with it keeps its rule.
     *
     * The counterpart of the test above, and the reason the decision is a count
     * rather than a policy: on an empty module the blueprint's own rules are
     * installed exactly as they are written.
     */
    public function testARequiredFieldKeepsItsRuleWhenThereAreNoRecords(): void
    {
        $this->takeEverything();

        self::assertTrue($this->inTenant(fn (): bool => $this->field('owner')->isRequired()));
    }

    /**
     * A derived field arrives empty, and nothing here invents a value for it.
     *
     * Derived values belong to whatever derives them (§5.9). A total or a
     * document number written by this code would look right and be wrong, so the
     * definition arrives and the record stays as it was until it is next saved.
     */
    public function testADerivedFieldArrivesEmptyOnRecordsThatAlreadyExist(): void
    {
        $this->aRecord('First');

        $this->takeEverything();

        self::assertTrue($this->inTenant(fn (): bool => $this->field('total')->isDerived()));

        // The **stored** payload rather than the hydrated record, deliberately.
        // Hydration fills a key for every field the shape now has, so reading it
        // back through the repository would answer "null" to a question that is
        // really about whether anything was written at all.
        $stored = $this->inTenant(fn (): array => json_decode(
            (string) self::service(Connection::class, 'doctrine.dbal.tenant_connection')
                ->fetchOne(sprintf('SELECT data FROM %s ORDER BY id', GrownModule::TABLE)),
            true,
            flags: \JSON_THROW_ON_ERROR,
        ));

        self::assertSame('First', $stored['name']);
        self::assertArrayNotHasKey('total', $stored, 'nothing was written into the record');
        self::assertArrayNotHasKey('owner', $stored);
    }

    /**
     * A field the customer already has is never offered, whatever it now looks
     * like.
     *
     * The whole of the protection a customised field gets, and deliberately
     * cruder than comparing the definition with the blueprint: a `notes` field
     * that is theirs, of another type and under another label, is theirs.
     */
    public function testAFieldTheCustomerAlreadyHasIsNotOffered(): void
    {
        $this->inTenant(function (): void {
            self::service(MetadataEditor::class)->addField(
                shape: $this->module(),
                key: 'notes',
                label: 'Remarks',
                type: 'text',
            );
        });

        self::assertNotContains('field:notes', $this->offered());

        $this->takeEverything();

        $notes = $this->inTenant(fn (): FieldDefinition => $this->field('notes'));
        self::assertSame('Remarks', $notes->getLabel(), 'and it is still theirs afterwards');
        self::assertFalse($notes->isSystem());
    }

    /**
     * A field they deleted on purpose is not offered back.
     *
     * **The design question of this ticket.** After §5.4's removal the two cases
     * are indistinguishable — a field somebody deleted and one they never had
     * are both "a key this shape has not got" — so nothing is inferred
     * afterwards and the decision is written down at the moment it is made,
     * which is the only moment it is unambiguous.
     */
    public function testAFieldTheCustomerRemovedIsNotOfferedAgain(): void
    {
        $this->inTenant(function (): void {
            $editor = self::service(MetadataEditor::class);
            $editor->addField(shape: $this->module(), key: 'notes', label: 'Remarks', type: 'text');
            $editor->removeField($this->field('notes'));
        });

        self::assertNotContains('field:notes', $this->offered());
        self::assertContains('field:notes', $this->offered(dismissed: true), 'and it is visibly a decision');

        $this->takeEverything();

        self::assertNotContains('notes', $this->fieldKeys(), 'so taking everything else leaves it out');
    }

    /**
     * Saying no is remembered, and can be taken back.
     *
     * The second half is what stops the first from being a trap: a decision
     * nobody can see is not a decision, it is a disappearance.
     */
    public function testADismissedAdditionIsRememberedAndCanBeTakenBack(): void
    {
        $this->client->request('POST', $this->url('/m/grown/upgrade/dismiss'), [
            '_token' => $this->token(),
            'addition' => $this->tokenFor('collection:parts'),
        ]);

        self::assertNotContains('collection:parts', $this->offered());
        self::assertContains('collection:parts', $this->offered(dismissed: true));

        $this->takeEverything();
        self::assertSame([], $this->inTenant(fn (): array => $this->module()->getCollectionKeys()));

        $this->client->request('POST', $this->url('/m/grown/upgrade/restore'), [
            '_token' => $this->token(),
            'addition' => $this->tokenFor('collection:parts', dismissed: true),
        ]);

        self::assertContains('collection:parts', $this->offered());
    }

    /**
     * A preset's extra fields are the same question by another name (§6.1).
     *
     * Nothing records which preset a module was installed with, on purpose, and
     * nothing needs to: every preset names a subset of the blueprint's own
     * fields, so the difference between what this customer has and what the
     * module declares already covers "the extended shape" without anything
     * remembering the word.
     */
    public function testAPresetsExtraFieldsAreOfferedLater(): void
    {
        $this->inTenant(function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
                preset: 'basic',
            );
        });

        $offered = $this->offered(module: ContactModule::KEY);

        self::assertContains('field:payment_terms', $offered, 'the field XIV-67 added');
        self::assertContains('field:phone', $offered, 'and the ones the smaller preset left out');
        self::assertContains('field:birthday', $offered);
        self::assertNotContains('field:email', $offered, 'while what basic installs is not offered again');
    }

    /**
     * Administrators only, on the metadata editor's authority rather than the
     * store's (§5.4, §8.4.3).
     *
     * A store grant says who may put a new module in the installation; this
     * changes the shape of every record in one that is already there.
     */
    public function testOnlyAnAdministratorMayTakeWhatAModuleGrew(): void
    {
        $this->signIn(self::ORDINARY);

        $this->client->request('GET', $this->url('/m/grown/upgrade'));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', $this->url('/m/grown/upgrade/take'), [
            '_token' => $this->token(),
            'additions' => [],
            'confirm' => '1',
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Yesterday's shape: one field of a module that now has five and a
     * collection.
     *
     * A blueprint rather than a preset, because a preset can only leave out
     * *fields* (§6.1) and half of what this ticket is about is the collection.
     */
    private static function reducedBlueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: GrownModule::KEY,
            label: 'Grown',
            table: GrownModule::TABLE,
            fields: [
                new FieldBlueprint(
                    key: 'name',
                    label: 'Name',
                    type: 'text',
                    required: true,
                    title: true,
                    position: 10,
                ),
            ],
        );
    }

    /**
     * The offers on the page, as `kind:key` rather than as their tokens.
     *
     * A token carries the shape's database id, which is exactly what a test
     * should not be asserting about; what a reader of this file wants to know is
     * *which* additions are on offer.
     *
     * @return list<string>
     */
    private function offered(string $module = GrownModule::KEY, bool $dismissed = false): array
    {
        $rows = $this->upgradePage($module)
            ->filter($dismissed ? 'form[action$="/restore"] button[name="addition"]' : 'input[name="additions[]"]')
            ->extract(['value']);

        return array_values(array_map(self::withoutShape(...), $rows));
    }

    /** One of them, as the page's own token, ready to be posted back. */
    private function tokenFor(string $addition, bool $dismissed = false): string
    {
        foreach ($this->upgradePage()
            ->filter($dismissed ? 'form[action$="/restore"] button[name="addition"]' : 'input[name="additions[]"]')
            ->extract(['value']) as $token
        ) {
            if (self::withoutShape($token) === $addition) {
                return $token;
            }
        }

        self::fail(sprintf('"%s" is not on offer.', $addition));
    }

    /** @return list<string> */
    private function tokens(): array
    {
        return array_values(array_map(
            strval(...),
            $this->upgradePage()->filter('input[name="additions[]"]')->extract(['value']),
        ));
    }

    /** `field:12:notes` is `field:notes` once the shape's id is out of it. */
    private static function withoutShape(string $token): string
    {
        $parts = explode(':', $token);

        return $parts[0] . ':' . ($parts[2] ?? '');
    }

    private function upgradePage(string $module = GrownModule::KEY): Crawler
    {
        return $this->client->request('GET', $this->url(sprintf('/m/%s/upgrade', $module)));
    }

    /** @param list<string> $tokens */
    private function review(array $tokens): Crawler
    {
        return $this->client->request('POST', $this->url('/m/grown/upgrade/review'), [
            '_token' => $this->token(),
            'additions' => $tokens,
        ]);
    }

    /** Through both pages, the way somebody would. */
    private function takeEverything(): void
    {
        $tokens = $this->tokens();

        if ($tokens === []) {
            return;
        }

        $this->client->request('POST', $this->url('/m/grown/upgrade/take'), [
            '_token' => $this->token(),
            'additions' => $tokens,
            'confirm' => '1',
        ]);
    }

    private function aRecord(string $name): void
    {
        $this->saveRecord(GrownModule::KEY, ['name' => $name]);
    }

    /** @return list<string> */
    private function fieldKeys(): array
    {
        return $this->inTenant(fn (): array => $this->module()->getFieldKeys());
    }

    private function field(string $key): FieldDefinition
    {
        $field = $this->module()->getField($key);
        self::assertNotNull($field, sprintf('no field "%s"', $key));

        return $field;
    }

    private function module(): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(GrownModule::KEY);
    }

    private static function tableExists(string $table): bool
    {
        return self::service(Connection::class, 'doctrine.dbal.tenant_connection')
            ->createSchemaManager()
            ->tablesExist([$table]);
    }

    /**
     * The CSRF token the metadata editor's forms carry.
     *
     * Read off a rendered page rather than generated, so that a change to which
     * token id these forms use fails here rather than passing against a token
     * nothing checks.
     */
    private function token(): string
    {
        $page = $this->client->request('GET', $this->url('/m/grown/upgrade'));
        $token = $page->filter('input[name="_token"]')->first();

        return $token->count() === 0 ? '' : (string) $token->attr('value');
    }

    private function inTenant(callable $work): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, $work);
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
    }

    private function signIn(string $email = self::EMAIL): void
    {
        // Out first, because signing in while signed in lands on the dashboard:
        // the login page redirects rather than drawing a form nobody needs.
        $this->client->request('GET', $this->url('/logout'));

        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @param string|null     $serviceId the container id, when it is not the class —
     *                                   the tenant connection is one of two `Connection`s
     *                                   and autowiring is what tells them apart
     *
     * @return T
     */
    private static function service(string $id, ?string $serviceId = null): object
    {
        $service = self::getContainer()->get($serviceId ?? $id);
        \assert($service instanceof $id);

        return $service;
    }
}
