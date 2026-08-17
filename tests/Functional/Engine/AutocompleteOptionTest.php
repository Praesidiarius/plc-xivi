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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * Typing to narrow a field, as an option rather than as a field type (XIV-36).
 *
 * The question this ticket answered was whether autocomplete needs a field type
 * of its own, and the answer was no: a type owns what a value *means* — its
 * storage, its validation, its operators, its display — and how somebody picks
 * it is none of those. So the assertions here come in two halves, and the second
 * half is the one that would catch the design being wrong.
 *
 * **What changes** is the widget: a select below the threshold, a search box
 * above it, `always` and `never` overriding the count, and a `choice` doing all
 * of that with no endpoint at all because its options are already in the page.
 *
 * **What does not change** is everything else about the value. A reference with
 * autocomplete on stores the same integer, validates the same way, filters
 * through the same predicate and prints the same name. If any of the tests at
 * the bottom of this file ever has to be relaxed, the option had become a type
 * after all and the argument in {@see Autocomplete} is wrong.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class AutocompleteOptionTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_autocomplete';
    private const string HOST = 'autocomplete.localhost';
    private const string EMAIL = 'admin@autocomplete.test';
    private const string PASSWORD = 'autocomplete-password';
    private const string FORM = 'module_record';

    /** A reference at contacts, left to decide for itself. */
    private const string SUPPLIER = 'supplier';

    /** A long choice list, likewise. */
    private const string REGION = 'region';

    /** A long choice list told to stay a select. */
    private const string ZONE = 'zone';

    /** A short one told to be a search box anyway. */
    private const string GRADE = 'grade';

    /** A short one left alone, which is the ordinary field on any form. */
    private const string SIZE = 'size';

    /** The Stimulus controller ux-autocomplete puts on a field it has taken over. */
    private const string CONTROLLER = 'symfony--ux-autocomplete--autocomplete';

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
            $editor = self::service(MetadataEditor::class);

            $installer->install($registry->get(ContactModule::KEY));
            $article = $installer->install($registry->get(ArticleModule::KEY));

            // Everything is added through the editor rather than by changing a
            // module, because a customer's own fields are the case this has to
            // work for and the blueprints are not where an option like this
            // belongs (§6.1).
            $fields = [
                self::SUPPLIER => ['reference', [
                    'module' => ContactModule::KEY,
                    'variant' => ContactModule::COMPANY,
                ]],
                self::REGION => ['choice', [ChoiceFieldType::CHOICES => self::manyChoices()]],
                self::ZONE => ['choice', [
                    ChoiceFieldType::CHOICES => self::manyChoices(),
                    Autocomplete::OPTION => Autocomplete::Never->value,
                ]],
                self::GRADE => ['choice', [
                    ChoiceFieldType::CHOICES => ['a' => 'A', 'b' => 'B', 'c' => 'C'],
                    Autocomplete::OPTION => Autocomplete::Always->value,
                ]],
                self::SIZE => ['choice', [ChoiceFieldType::CHOICES => ['s' => 'S', 'm' => 'M', 'l' => 'L']]],
            ];

            foreach ($fields as $key => [$type, $options]) {
                if ($article->getField($key) === null) {
                    $editor->addField($article, $key, ucfirst($key), $type, filterable: true, options: $options);
                }
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- what the option decides --------------------------------------------

    /**
     * A long list of options is typed at, and asks nobody anything to do it.
     *
     * The cheap half of the ticket, and the reason it is cheap: the choices are
     * a closed list in the field's own settings, so they are in the page
     * already. No endpoint, which is what the absent URL asserts — a `choice`
     * that had grown one would be a permission question nobody had answered.
     */
    public function testALongChoiceListAutocompletesWithNoEndpointAtAll(): void
    {
        $field = $this->fieldOnTheForm(self::REGION);

        self::assertTrue(self::isAutocompleting($field), 'it is a search box');
        self::assertNull(
            $field->attr('data-' . self::CONTROLLER . '-url-value'),
            'and it looks nothing up: the options are already here',
        );
        self::assertGreaterThan(
            Autocomplete::AUTO_ABOVE,
            $field->filter('option')->count(),
            'every option is still in the page, which is what makes that possible',
        );
    }

    /** A handful of options is scrolled, which is what a handful is for. */
    public function testAShortChoiceListStaysAPlainSelect(): void
    {
        self::assertFalse(self::isAutocompleting($this->fieldOnTheForm(self::SIZE)));
    }

    /**
     * `always` is not about the count, which is the reason the option is three
     * states rather than a tick box: somebody may want typing on a list of
     * three, and the engine has no way to know that from the data.
     */
    public function testAlwaysBeatsTheCount(): void
    {
        self::assertTrue(self::isAutocompleting($this->fieldOnTheForm(self::GRADE)));
    }

    /** And `never` is what a field wants forever, however long its list grows. */
    public function testNeverBeatsTheCount(): void
    {
        self::assertFalse(self::isAutocompleting($this->fieldOnTheForm(self::ZONE)));
    }

    // -- the reference, which is the half that needed an endpoint ------------

    /** Few enough candidates to scroll, so the picker is the one it always was. */
    public function testASmallReferenceStaysAPlainSelect(): void
    {
        $this->companies(3);

        $field = $this->fieldOnTheForm(self::SUPPLIER);

        self::assertFalse(self::isAutocompleting($field));
        self::assertCount(4, $field->filter('option'), 'the placeholder and the three of them');
    }

    /**
     * Past the threshold it becomes a search box pointed at the endpoint —
     * generic over module and variant, which is what the URL says.
     */
    public function testAReferencePastTheThresholdSearchesTheEndpoint(): void
    {
        $this->companies(Autocomplete::AUTO_ABOVE + 1);

        $field = $this->fieldOnTheForm(self::SUPPLIER);
        $url = (string) $field->attr('data-' . self::CONTROLLER . '-url-value');

        self::assertTrue(self::isAutocompleting($field));
        self::assertStringContainsString('/m/' . ContactModule::KEY . '/search', $url, 'one route, per module');
        self::assertStringContainsString('variant=' . ContactModule::COMPANY, $url, 'narrowed the way the select was');
    }

    /**
     * And it preloads nothing, which is the point rather than an omission.
     *
     * A dropdown of the first two hundred was the thing XIV-35 had to apologise
     * for. There is no page of candidates in the markup at all now: the widget
     * asks the endpoint when somebody focuses it and pages through the rest as
     * they scroll, so the ceiling is gone rather than raised.
     */
    public function testAnAutocompletingReferenceShipsNoCandidatesInThePage(): void
    {
        $this->companies(Autocomplete::AUTO_ABOVE + 1);

        $field = $this->fieldOnTheForm(self::SUPPLIER);

        self::assertCount(1, $field->filter('option'), 'the placeholder, and nothing else');
        self::assertCount(0, $field->filter('#' . self::idOf(self::SUPPLIER) . '_help'), 'nothing to apologise for');
    }

    /**
     * An edit form still shows what the record is linked to.
     *
     * The case a lazily loaded choice list gets wrong: the record points at a
     * company that is not in any preloaded page — there is no preloaded page —
     * so unless the form is told about it, opening an article would silently
     * clear its supplier the moment somebody pressed save.
     */
    public function testAnEditFormStillShowsTheRecordItIsAlreadyLinkedTo(): void
    {
        $companies = $this->companies(Autocomplete::AUTO_ABOVE + 1);
        $linked = $companies[\count($companies) - 1];

        $article = $this->savedId($this->saveRecord(ArticleModule::KEY, [
            'title' => 'Desk lamp',
            self::SUPPLIER => (string) $linked,
        ]));

        $field = $this->fieldOnTheForm(self::SUPPLIER, '/m/article/' . $article . '/edit');
        $selected = $field->filter('option[selected]');

        self::assertCount(1, $selected, 'the one it points at is in the list');
        self::assertSame((string) $linked, $selected->attr('value'));
        self::assertStringContainsString('Company', (string) $selected->text(), 'named, not numbered');
    }

    /**
     * Picking something the page never rendered saves (XIV-36).
     *
     * **This is the assertion the choice loader exists for.** ChoiceType
     * validates a submitted value against the choices it drew, and an
     * autocompleting picker draws none — so without a loader the record the
     * widget just offered would come back as "This value is not valid". The id
     * submitted here is a company from the far end of the alphabet, which no
     * first page would have contained even if there had been one.
     */
    public function testARecordFoundByTypingCanBeSaved(): void
    {
        $companies = $this->companies(Autocomplete::AUTO_ABOVE + 1);
        $wanted = $companies[\count($companies) - 1];

        $article = $this->savedId($this->saveRecord(ArticleModule::KEY, [
            'title' => 'Desk lamp',
            self::SUPPLIER => (string) $wanted,
        ]));

        self::assertSame($wanted, $this->supplierOf($article), 'it stored the id it was given');
    }

    // -- and the half that has to stay exactly as it was ---------------------

    /**
     * The option decides a widget and nothing else.
     *
     * Asked of the type rather than of a page, because that is where the claim
     * lives: a field type owns storage, validation, operators and display, and
     * none of the four may notice the option at all. Two definitions of the same
     * type, one autocompleting and one not, have to be indistinguishable to
     * every one of them.
     */
    public function testTurningItOnChangesNothingAboutWhatTheValueMeans(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $article = self::service(MetadataRepository::class)->get(ArticleModule::KEY);
            $types = self::service(FieldTypeRegistry::class);

            $searched = $article->getField(self::REGION);
            $scrolled = $article->getField(self::ZONE);
            self::assertNotNull($searched);
            self::assertNotNull($scrolled);

            $type = $types->get($searched->getType());

            self::assertEquals($type->constraints($scrolled), $type->constraints($searched), 'the same validation');
            self::assertSame($type->operators(), $type->operators(), 'the same comparisons');
            self::assertSame(
                $type->toStorage('r05', $scrolled),
                $type->toStorage('r05', $searched),
                'stored identically',
            );
            self::assertSame(
                $type->display('r05', $scrolled),
                $type->display('r05', $searched),
                'and printed identically — which is what an export writes',
            );
        });
    }

    /**
     * A record saved through a search box filters like any other.
     *
     * The end of the same argument, one layer up: the list, the filter bar and
     * the query layer never learn that a widget was involved, because the value
     * is an id either way.
     */
    public function testAValuePickedByTypingStillFilters(): void
    {
        $companies = $this->companies(Autocomplete::AUTO_ABOVE + 1);
        $wanted = $companies[0];

        $this->saveRecord(ArticleModule::KEY, ['title' => 'Desk lamp', self::SUPPLIER => (string) $wanted]);
        $this->saveRecord(ArticleModule::KEY, ['title' => 'Cable']);

        $table = $this->client->request('GET', $this->url(sprintf(
            '/m/article?filter[0][path]=%s&filter[0][op]=eq&filter[0][value]=%d',
            self::SUPPLIER,
            $wanted,
        )))->filter('table')->text();

        self::assertStringContainsString('Desk lamp', $table);
        self::assertStringNotContainsString('Cable', $table);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Twenty-five options, which is more than a dropdown is worth scrolling.
     *
     * @return array<string, string>
     */
    private static function manyChoices(): array
    {
        $choices = [];

        foreach (range(1, 25) as $n) {
            $choices[sprintf('r%02d', $n)] = sprintf('Region %02d', $n);
        }

        return $choices;
    }

    /**
     * @return list<int> the ids, in the order their names sort
     */
    private function companies(int $count): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($count): array {
            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $writer = self::service(RecordWriter::class);
            $ids = [];

            foreach (range(1, $count) as $n) {
                $saved = $writer->save($contacts, new Record(data: [
                    'kind' => ContactModule::COMPANY,
                    'company_name' => sprintf('Company %03d', $n),
                ]));

                $ids[] = (int) $saved->id;
            }

            return $ids;
        });
    }

    private function supplierOf(int $article): ?int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($article): ?int {
            $articles = self::service(MetadataRepository::class)->get(ArticleModule::KEY);
            $record = self::service(RecordRepository::class)->find($articles, $article);
            self::assertNotNull($record);

            $value = $record->get(self::SUPPLIER);

            return is_numeric($value) ? (int) $value : null;
        });
    }

    /** One field's control on a record form, as it is actually drawn. */
    private function fieldOnTheForm(string $key, string $path = '/m/article/new'): Crawler
    {
        $control = $this->client->request('GET', $this->url($path))->filter('#' . self::idOf($key));

        self::assertCount(1, $control, sprintf('"%s" is on the form', $key));

        return $control;
    }

    /**
     * Whether ux-autocomplete has taken a control over.
     *
     * The Stimulus controller name on the element, which is the one thing that
     * is true of every autocompleting field and of nothing else — a URL is only
     * on the ones that search a server.
     */
    private static function isAutocompleting(Crawler $field): bool
    {
        return str_contains((string) $field->attr('data-controller'), self::CONTROLLER);
    }

    private static function idOf(string $key): string
    {
        return sprintf('%s_fields_%s', self::FORM, $key);
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
