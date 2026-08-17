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
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Numbering\NumberFormat;
use Xivi\Core\Record\InheritedValue;
use Xivi\Order\OrderModule;

/**
 * What a field keeps when somebody edits it (XIV-26).
 *
 * Options are where the declarative half of the engine lives — a choice field's
 * values, a reference's target, what an order line inherits, how a document is
 * numbered — and the editor's form draws three settings and knows nothing about
 * any of that. It used to replace the lot, so **renaming a label wiped every
 * option the form had never heard of**, and none of it could be typed back in.
 *
 * One test per kind of option, all of them through the editor's own form rather
 * than the service behind it: the bug was in what the form sent, so a test that
 * called the service would have passed throughout.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FieldOptionsTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_field_options';
    private const string HOST = 'fieldoptions.localhost';
    private const string EMAIL = 'options@example.test';
    private const string PASSWORD = 'options-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            foreach ([ContactModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Options', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /** The one that started it: a numbered field stays numbered. */
    public function testANumberedFieldKeepsItsPattern(): void
    {
        $before = $this->optionsOf(OrderModule::KEY, OrderModule::NUMBER);
        self::assertArrayHasKey(NumberFormat::OPTION, $before, 'it starts numbered');

        $this->rename(OrderModule::KEY, OrderModule::NUMBER, 'Belegnummer');

        self::assertSame($before, $this->optionsOf(OrderModule::KEY, OrderModule::NUMBER));
    }

    /**
     * And one the *customer* typed keeps being theirs (XIV-27).
     *
     * The test above is about the seeded pattern surviving; this is the case
     * that only exists now that there is a control for it, and it is the one the
     * dependency between these two tickets was about. A pattern set on the
     * numbering page is written through the same merge — so it has to survive an
     * edit made on the field table, which knows nothing about numbering, and the
     * numbering page has to leave that field's other settings alone in return.
     */
    public function testAPatternTheCustomerTypedSurvivesAnUnrelatedEdit(): void
    {
        $this->setNumbering(OrderModule::KEY, OrderModule::NUMBER, 'AUF-{year}-{number:5}');

        $options = $this->optionsOf(OrderModule::KEY, OrderModule::NUMBER);
        self::assertSame('AUF-{year}-{number:5}', $options[NumberFormat::OPTION] ?? null);
        self::assertSame(40, $options['max_length'] ?? null, 'and the setting the numbering page never drew');

        $this->rename(OrderModule::KEY, OrderModule::NUMBER, 'Belegnummer');

        self::assertSame(
            'AUF-{year}-{number:5}',
            $this->optionsOf(OrderModule::KEY, OrderModule::NUMBER)[NumberFormat::OPTION] ?? null,
            'still theirs, after an edit that was about the label',
        );
    }

    /** A choice field keeps its choices, which for a lifecycle are its states. */
    public function testAChoiceFieldKeepsItsChoices(): void
    {
        $this->rename(OrderModule::KEY, 'status', 'Stand');

        $options = $this->optionsOf(OrderModule::KEY, 'status');

        self::assertArrayHasKey('choices', $options);
        self::assertSame(
            [OrderModule::DRAFT, OrderModule::CONFIRMED, OrderModule::DELIVERED, OrderModule::CANCELLED],
            array_keys((array) $options['choices']),
        );
    }

    /**
     * And a variant field keeps its variants, which is the same option doing a
     * much bigger job: without it the module has no kinds and its records are of
     * a kind that no longer exists (§5.5).
     */
    public function testAVariantFieldKeepsItsVariants(): void
    {
        $this->rename(ContactModule::KEY, 'kind', 'Art');

        $module = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(MetadataRepository::class)->get(ContactModule::KEY)->getVariants(),
        );

        self::assertSame(['person', 'company'], array_keys($module));
    }

    /** A reference keeps the module it points at, or the link resolves to nothing. */
    public function testAReferenceFieldKeepsItsTarget(): void
    {
        $this->rename(OrderModule::KEY, 'contact', 'Auftraggeberin');

        self::assertSame(
            ContactModule::KEY,
            $this->optionsOf(OrderModule::KEY, 'contact')[ReferenceFieldType::MODULE] ?? null,
        );
    }

    /** An inheriting field keeps what it inherits, and its own settings beside it. */
    public function testAnInheritingFieldKeepsWhatItInherits(): void
    {
        $this->rename(OrderModule::KEY, OrderModule::UNIT_PRICE, 'Ansatz', OrderModule::LINES);

        $options = $this->optionsOf(OrderModule::KEY, OrderModule::UNIT_PRICE, OrderModule::LINES);

        self::assertArrayHasKey(InheritedValue::OPTION, $options);
        self::assertSame('article', ((array) $options[InheritedValue::OPTION])['reference'] ?? null);
    }

    /** A decimal keeps its places, which decide what its values even mean. */
    public function testADecimalKeepsItsScale(): void
    {
        $this->rename(OrderModule::KEY, OrderModule::QUANTITY, 'Anzahl', OrderModule::LINES);

        self::assertSame(2, $this->optionsOf(OrderModule::KEY, OrderModule::QUANTITY, OrderModule::LINES)['scale'] ?? null);
    }

    /**
     * The other half of the rule: a setting the form *does* draw can still be
     * emptied. A merge that could only ever add one would be the opposite bug.
     */
    public function testASettingTheFormDrawsCanStillBeCleared(): void
    {
        $key = 'description';

        self::assertSame(255, $this->optionsOf(OrderModule::KEY, $key, OrderModule::LINES)['max_length'] ?? null);

        $this->rename(OrderModule::KEY, $key, 'Bezeichnung', OrderModule::LINES, ['max_length' => '']);

        $options = $this->optionsOf(OrderModule::KEY, $key, OrderModule::LINES);

        self::assertArrayNotHasKey('max_length', $options, 'emptied, not left at 255');
        self::assertArrayHasKey(InheritedValue::OPTION, $options, 'and its neighbours are still there');
    }

    /** And one it draws can be changed, which is what the form is for. */
    public function testASettingTheFormDrawsCanStillBeChanged(): void
    {
        $this->rename(OrderModule::KEY, 'description', 'Bezeichnung', OrderModule::LINES, ['max_length' => '120']);

        self::assertSame(120, $this->optionsOf(OrderModule::KEY, 'description', OrderModule::LINES)['max_length'] ?? null);
    }

    /**
     * A field told how it is picked keeps being told (XIV-36).
     *
     * The newest option, and the one most likely to be lost the way the others
     * were: it is *drawn* by this form, so it lives on the far side of the same
     * merge — named on every save, cleared when blank, and therefore capable of
     * being wiped by a rename if that merge ever stopped naming it. Set here
     * through the control a customer uses rather than by writing the definition,
     * because the bug XIV-26 was about was in what the form sent.
     */
    public function testAFieldKeepsHowItIsPicked(): void
    {
        $this->rename(OrderModule::KEY, 'contact', 'Auftraggeberin', settings: [
            Autocomplete::OPTION => Autocomplete::Never->value,
        ]);

        self::assertSame(
            Autocomplete::Never->value,
            $this->optionsOf(OrderModule::KEY, 'contact')[Autocomplete::OPTION] ?? null,
        );

        // And a second, unrelated save leaves it alone — which is the whole
        // claim, since the first save is the one that set it.
        $this->rename(OrderModule::KEY, 'contact', 'Kundin');

        self::assertSame(
            Autocomplete::Never->value,
            $this->optionsOf(OrderModule::KEY, 'contact')[Autocomplete::OPTION] ?? null,
            'still never, after an edit that was about the label',
        );
        self::assertSame(
            ContactModule::KEY,
            $this->optionsOf(OrderModule::KEY, 'contact')[ReferenceFieldType::MODULE] ?? null,
            'and its neighbours are still there',
        );
    }

    /**
     * A field whose type has nothing to autocomplete never grows the option.
     *
     * The other half of the type-aware control: the editor draws it only where
     * it means something, so a `text` field's save says nothing about
     * autocomplete at all — and a setting a form does not mention is one it
     * cannot invent any more than it can wipe.
     */
    public function testAFieldWithNothingToPickDoesNotGrowTheOption(): void
    {
        $this->rename(OrderModule::KEY, 'description', 'Bezeichnung', OrderModule::LINES);

        self::assertArrayNotHasKey(
            Autocomplete::OPTION,
            $this->optionsOf(OrderModule::KEY, 'description', OrderModule::LINES),
        );
    }

    /** The rename itself still happens — the point of the page (XIV-8). */
    public function testTheLabelIsStillChanged(): void
    {
        $this->rename(OrderModule::KEY, 'status', 'Stand');

        self::assertSame('Stand', $this->fieldOf(OrderModule::KEY, 'status')->getLabel());
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Saves a field through the editor's own form, changing its label.
     *
     * @param array<string, string> $settings anything else the form draws
     */
    private function rename(
        string $module,
        string $field,
        string $label,
        ?string $collection = null,
        array $settings = [],
    ): void {
        $id = $this->fieldOf($module, $field, $collection)->getId();

        $crawler = $this->client->request('GET', $this->url('/m/' . $module . '/fields'));
        $form = $crawler->filter('form[action$="/fields/' . $id . '"]')->form();
        $form['label'] = $label;

        foreach ($settings as $name => $value) {
            $form[$name] = $value;
        }

        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    /** Sets a field's numbering pattern through the page a customer uses (XIV-27). */
    private function setNumbering(string $module, string $field, string $pattern): void
    {
        $id = $this->fieldOf($module, $field)->getId();

        $crawler = $this->client->request('GET', $this->url(sprintf('/m/%s/fields/%d/numbering', $module, $id)));
        $form = $crawler->filter('form[action$="/numbering"]')->form();
        $form[NumberFormat::OPTION] = $pattern;

        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    /** @return array<string, mixed> */
    private function optionsOf(string $module, string $field, ?string $collection = null): array
    {
        return $this->fieldOf($module, $field, $collection)->getOptions();
    }

    private function fieldOf(string $module, string $field, ?string $collection = null): FieldDefinition
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function () use ($module, $field, $collection): FieldDefinition {
                $definition = self::service(MetadataRepository::class)->get($module);
                $shape = $collection === null ? $definition : $definition->getCollection($collection);
                self::assertNotNull($shape);

                $found = $shape->getField($field);
                self::assertInstanceOf(FieldDefinition::class, $found);

                return $found;
            },
        );
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
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
