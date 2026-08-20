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
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Form\RecordChoiceLoader;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordCandidates;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * The choice list behind an autocompleting picker (XIV-167).
 *
 * **Everything here is about the autocompleting half and nothing else reaches
 * it.** `RecordReferenceType` only fits a `choice_loader` when the widget is a
 * search box; below {@see Autocomplete::AUTO_ABOVE} candidates the picker is a
 * plain select reading {@see \Xivi\Core\Form\CandidateLists}, which is safe from
 * both of these defects because it reads through
 * {@see RecordCandidates::find()}. That is why a demo tenant full of articles
 * showed them and a small fixture did not, and it is the first thing a test here
 * has to get right: **a test that forgets to force autocomplete passes on broken
 * code.** Hence `always` on both fields below, however few contacts the fixture
 * makes.
 *
 * Two defects, found separately, in about forty lines of one class.
 *
 * **Two records sharing a title collapsed into one.** The loader collected
 * label => id, and every record it collects arrives one at a time through
 * {@see RecordCandidates::byId()}, which has nothing to disambiguate a single
 * record against and so answers with the bare title. Two links called the same
 * thing wrote the same array key: the edit form drew one option, showed one
 * selection, and the other link was gone the moment anybody saved. The select
 * path had solved this years earlier, inside `find()`, which is why the picker
 * on a *new* record was right and only the edit form was wrong.
 *
 * **And the loader re-indexed the answer ChoiceType reads by key.**
 * `ChoiceLoaderInterface` promises the choices come back under the keys they
 * were asked for; this appended instead. ChoiceType then walks
 * `$knownValues[] = $data[$key]`, so every key after a refused value was off by
 * one: off the end of the array when the keys did not start at zero, and onto
 * the refused value itself when one of three was turned down. Both ended as a
 * refused save of a set the reader had picked out of the widget's own
 * suggestions, which is why they are asserted here as saves rather than as
 * messages.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ReferenceChoiceLoaderTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_choice_loader';
    private const string HOST = 'choiceloader.localhost';
    private const string EMAIL = 'admin@choiceloader.test';
    private const string PASSWORD = 'choiceloader-password';
    private const string FORM = 'module_record';

    /** A field naming several companies, always a search box. */
    private const string LINKED = 'suppliers';

    /** And the single one beside it, which is immune to the first defect. */
    private const string ONE = 'main_supplier';

    /** What two records are both called, so that they collide. */
    private const string TWIN = 'Aktenregal Basis';

    /**
     * An id no record has.
     *
     * Far past anything a fresh tenant's identity sequence reaches, and the
     * point is that the loader treats it exactly as it treats a deleted record,
     * another customer's id or the wrong variant: null, indistinguishably
     * (§8.4).
     */
    private const int GONE = 987654321;

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

            // `always` rather than a fixture of twenty-one companies. The
            // threshold is what decides the widget in production and it is
            // tested where it belongs, in AutocompleteOptionTest; here it would
            // only be twenty records standing between a reader and the subject.
            $options = [
                ReferenceFieldType::MODULE => ContactModule::KEY,
                ReferenceFieldType::VARIANT => ContactModule::COMPANY,
                Autocomplete::OPTION => Autocomplete::Always->value,
            ];

            foreach ([self::LINKED => 'multi_reference', self::ONE => 'reference'] as $key => $type) {
                if ($article->getField($key) === null) {
                    $editor->addField($article, $key, ucfirst($key), $type, options: $options);
                }
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- the first defect: two targets sharing a title -----------------------

    /**
     * An edit form on a set holding two records called the same thing shows
     * both.
     *
     * **The test that fails on the old code**, and it fails by counting one
     * where it wanted two: the second offer overwrote the first in an array
     * keyed by label, so `ArrayChoiceList` was built from a single entry. The
     * control that proves the collision is the cause rather than the arity is
     * {@see self::testTwoTargetsWithNamesOfTheirOwnWereNeverTheProblem()}, which
     * is the same set with the titles changed and nothing else.
     */
    public function testAnEditFormShowsBothOfTwoRecordsThatShareATitle(): void
    {
        [$one, $two] = $this->companies(self::TWIN, self::TWIN);
        $article = $this->articleLinking([$one, $two]);

        $field = $this->fieldOnTheForm(self::LINKED, $article);

        self::assertSame([$one, $two], self::valuesOf($field->filter('option')), 'both are options');
        self::assertSame([$one, $two], self::valuesOf($field->filter('option[selected]')), 'and both are picked');
    }

    /** The same shape with distinct titles, which is what was always fine. */
    public function testTwoTargetsWithNamesOfTheirOwnWereNeverTheProblem(): void
    {
        [$one, $two] = $this->companies('Regal A', 'Regal B');
        $article = $this->articleLinking([$one, $two]);

        $field = $this->fieldOnTheForm(self::LINKED, $article);

        self::assertSame([$one, $two], self::valuesOf($field->filter('option[selected]')));
        self::assertSame(['Regal A', 'Regal B'], self::labelsOf($field->filter('option')), 'and neither is numbered');
    }

    /**
     * Both of a colliding pair carry the id, and the picker spells them the same
     * way.
     *
     * **The deliberate half of the fix**, and the argument for it is in
     * {@see \Xivi\Core\Record\DistinctLabels}. The rule this reuses used to leave
     * the *first* of a pair spelled plainly, which cannot survive having two
     * callers: a search reads a page ordered by title and then `id DESC`, so it
     * meets the higher id first, while an edit form walks the stored array,
     * which §5.29 keeps sorted ascending, so it meets the lower one first. The
     * two would spell the same pair in opposite directions, and nobody could be
     * told which record they had picked.
     *
     * So the assertion is not only that the labels differ. It is that the two
     * halves of one field agree about what each record is called, which is why
     * the endpoint the widget searches is read here beside the form.
     */
    public function testBothOfThemCarryTheirIdAndTheSearchBoxAgrees(): void
    {
        [$one, $two] = $this->companies(self::TWIN, self::TWIN);
        $article = $this->articleLinking([$one, $two]);

        $spelled = [
            sprintf('%s (#%d)', self::TWIN, $one),
            sprintf('%s (#%d)', self::TWIN, $two),
        ];

        self::assertSame(
            $spelled,
            self::labelsOf($this->fieldOnTheForm(self::LINKED, $article)->filter('option')),
            'the form tells them apart',
        );

        $found = $this->searchLabels();
        sort($found);

        self::assertSame($spelled, $found, 'and the search box calls them the same two things');
    }

    /**
     * One of a colliding pair, on its own, is spelled plainly.
     *
     * The scope of the suffix, said as an assertion rather than only in a
     * docblock: it means "there is another one of these here", not "this record
     * is number 47". A form holding one of the twins has nothing to tell it
     * apart from, and putting an id beside it there would put an id beside a
     * name on every form in the application.
     */
    public function testARecordWithNothingBesideItKeepsItsPlainName(): void
    {
        [$one] = $this->companies(self::TWIN, self::TWIN);
        $article = $this->articleLinking([$one]);

        self::assertSame(
            [self::TWIN],
            self::labelsOf($this->fieldOnTheForm(self::LINKED, $article)->filter('option')),
        );
    }

    // -- the second defect: the keys the loader answers under -----------------

    /**
     * A submission whose keys do not start at zero.
     *
     * **The test that fails on the old code**, and what it costs there depends
     * on how loudly the process treats a warning. ChoiceType reads `$data[$key]`
     * for each key the loader answered under; the loader answered under 0 and 1
     * for values submitted under 1 and 2, so it reaches past the end of the
     * array. Where warnings are exceptions the request dies on "Undefined array
     * key 0", which is how this was reported; where they are not, the shorter
     * list it assembled instead fails the transformer and the field somebody
     * filled in comes back refused. Neither is a save of what was submitted,
     * which is all this asserts.
     *
     * A Live Component model is where these keys come from: values travel as
     * JSON, and a list written sparsely arrives as an object with the keys it
     * was written under rather than as an array starting at zero.
     */
    public function testASubmissionWhoseKeysDoNotStartAtZeroSavesWhatWasSubmitted(): void
    {
        [$one, $two] = $this->companies('Regal A', 'Regal B');

        $saved = $this->saveRecord(ArticleModule::KEY, [
            'title' => 'Desk lamp',
            'price' => '19.90',
            self::LINKED => [1 => (string) $one, 2 => (string) $two],
        ]);

        self::assertSame([$one, $two], $this->linkedTo($this->savedId($saved)));
    }

    /**
     * A value the loader refuses vanishes, and the accepted ones stay where they
     * were asked about.
     *
     * **The contract itself, read straight off the loader**, because that is
     * where the substitution happens and the only place it is visible as itself.
     * Appending re-indexed the answer, so ChoiceType matched choice 0 against
     * value 0, the refused one, and carried *that* into the data it submits
     * while dropping a real value. Everything the test above asserts about the
     * form is downstream of these two keys.
     *
     * Worth having beside the save tests rather than folded into them: what
     * stopped the wrong set from being written was `ChoicesToValuesTransformer`
     * counting what came back, which is a caller being strict and not this class
     * being right. The next caller is under no such obligation.
     *
     * Asked with the refusal in front on purpose. In front is where a shift of
     * one moves every remaining key.
     */
    public function testARefusedValueVanishesAndTheOthersKeepTheirKeys(): void
    {
        [$one, $two] = $this->companies('Regal A', 'Regal B');

        self::assertSame(
            [1 => $one, 2 => $two],
            $this->loader()->loadChoicesForValues([(string) self::GONE, (string) $one, (string) $two]),
        );
    }

    /**
     * And a refusal is still a refusal all the way through the form.
     *
     * The other side of the same criterion, through the whole save: an id this
     * reader may not have drops out of the submission and the ones beside it are
     * written exactly as they were sent. `byId()` answers null for a deleted
     * record, another customer's id, the wrong variant and a number typed into
     * the request, and does not distinguish between them (§8.4), so one
     * non-existent id stands for all four.
     *
     * The refusal is in the **middle**, which on the old code is where it did
     * the most damage: the shift put the refused id into the data ChoiceType
     * submits and dropped the last real one, the transformer then found that
     * refused id was not a choice after all, and the whole save came back as
     * "The selected choice is invalid" about a set the reader had picked
     * entirely from the widget's own suggestions.
     */
    public function testASubmissionHoldingARefusedValueSavesOnlyWhatItAccepted(): void
    {
        [$one, $two] = $this->companies('Regal A', 'Regal B');
        $article = $this->articleLinking([$one, $two]);

        $saved = $this->saveRecord(ArticleModule::KEY, [
            'title' => 'Desk lamp',
            'price' => '19.90',
            self::LINKED => [(string) $one, (string) self::GONE, (string) $two],
        ], recordId: $article);

        self::assertTrue($saved->isRedirect(), 'the two it may have are two links it may make');
        self::assertSame([$one, $two], $this->linkedTo($article), 'and the id it may not have is not among them');
    }

    // -- and what had to keep working ----------------------------------------

    /** A save that touches nothing leaves the stored list exactly as it was. */
    public function testASaveThatChangesNothingLeavesTheSetAlone(): void
    {
        [$one, $two] = $this->companies(self::TWIN, self::TWIN);
        $article = $this->articleLinking([$one, $two]);

        $again = $this->saveRecord(ArticleModule::KEY, [
            'title' => 'Desk lamp',
            'price' => '19.90',
            self::LINKED => [(string) $one, (string) $two],
        ], recordId: $article);

        self::assertTrue($again->isRedirect(), 'a re-save of the same set is a save');
        self::assertSame([$one, $two], $this->linkedTo($article));
    }

    /**
     * The single `reference` still picks and saves.
     *
     * It is immune to the first defect, since one link cannot collide with
     * itself, and it goes through the same `loadChoicesForValues()` as the
     * other, so it is the arity the second fix could have broken.
     * `ChoiceToValueTransformer`
     * reads the answer with `current()` and counts it, which survives a key of 0
     * and a key of anything else; this asserts that rather than assuming it.
     */
    public function testTheSingleReferenceStillShowsAndSavesWhatItPointsAt(): void
    {
        [$one] = $this->companies('Regal A', 'Regal B');

        $article = $this->savedId($this->saveRecord(ArticleModule::KEY, [
            'title' => 'Desk lamp',
            'price' => '19.90',
            self::ONE => (string) $one,
        ]));

        $field = $this->fieldOnTheForm(self::ONE, $article);

        self::assertSame([$one], self::valuesOf($field->filter('option[selected]')));
        self::assertSame(['Regal A'], self::labelsOf($field->filter('option[selected]')));
    }

    // -- fixture and reading -------------------------------------------------

    /**
     * Companies with the names given, in the order given.
     *
     * @return list<int>
     */
    private function companies(string ...$names): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($names): array {
            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $writer = self::service(RecordWriter::class);
            $ids = [];

            foreach ($names as $name) {
                $saved = $writer->save($contacts, new Record(data: [
                    'kind' => ContactModule::COMPANY,
                    'company_name' => $name,
                ]));

                $ids[] = (int) $saved->id;
            }

            return $ids;
        });
    }

    /**
     * An article naming these companies, saved through the form.
     *
     * Through the form rather than the writer, because the write path is half of
     * what is under test: a picker that gave back one id where two were chosen
     * would be invisible to a fixture that wrote the array itself.
     *
     * @param list<int> $companies
     */
    private function articleLinking(array $companies): int
    {
        return $this->savedId($this->saveRecord(ArticleModule::KEY, [
            'title' => 'Desk lamp',
            'price' => '19.90',
            self::LINKED => array_map(strval(...), $companies),
        ]));
    }

    /**
     * What an article's set of links holds.
     *
     * @return list<int>
     */
    private function linkedTo(int $article): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($article): array {
            $articles = self::service(MetadataRepository::class)->get(ArticleModule::KEY);
            $record = self::service(RecordRepository::class)->find($articles, $article);
            self::assertNotNull($record, 'the article is still there');

            /** @var list<int|string> $value */
            $value = (array) ($record->get(self::LINKED) ?? []);

            return array_map(intval(...), array_values($value));
        });
    }

    /** One field's control on an article's edit form, as it is actually drawn. */
    private function fieldOnTheForm(string $key, int $article): Crawler
    {
        $control = $this->client
            ->request('GET', $this->url(sprintf('/m/article/%d/edit', $article)))
            ->filter(sprintf('#%s_fields_%s', self::FORM, $key));

        self::assertCount(1, $control, sprintf('"%s" is on the form', $key));

        return $control;
    }

    /**
     * What the widget's own endpoint calls the companies it can reach.
     *
     * The search box's half of the picker, read the way the widget reads it, so
     * that the claim about the two halves agreeing is asserted against the
     * endpoint rather than against the class behind it.
     *
     * @return list<string>
     */
    private function searchLabels(): array
    {
        $this->client->request('GET', $this->url('/m/contact/search?' . http_build_query([
            'query' => self::TWIN,
            'variant' => ContactModule::COMPANY,
        ])));

        self::assertResponseIsSuccessful();

        /** @var array{results: list<array{value: int, text: string}>} $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return array_map(static fn (array $result): string => $result['text'], $answer['results']);
    }

    /**
     * A loader on the multi-reference field, asked directly.
     *
     * **Somebody has to be reading.** Candidates are scoped to the current user
     * (§8.4, XIV-13) and there is no request here to carry a session, so the
     * token goes straight into the storage the security component reads: the
     * same arrangement {@see FieldTypeRoundTripTest} needs and for the same
     * reason. Without it `byId()` would answer null for every id and the
     * assertion would pass on an empty array.
     */
    private function loader(): RecordChoiceLoader
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function (): RecordChoiceLoader {
            $user = self::service(UserRepository::class)->findOneByEmail(self::EMAIL);
            self::assertInstanceOf(User::class, $user);

            self::service(TokenStorageInterface::class)
                ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

            return new RecordChoiceLoader(
                self::service(RecordCandidates::class),
                ContactModule::KEY,
                ContactModule::COMPANY,
            );
        });
    }

    /** @return list<int> */
    private static function valuesOf(Crawler $options): array
    {
        return $options->each(static fn (Crawler $option): int => (int) $option->attr('value'));
    }

    /** @return list<string> */
    private static function labelsOf(Crawler $options): array
    {
        return $options->each(static fn (Crawler $option): string => trim($option->text()));
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
