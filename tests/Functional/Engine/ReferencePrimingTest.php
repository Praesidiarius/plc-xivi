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
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\Dbal\MeasuresQueries;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Document\RepeatingBlocks;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordPrimer;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Record\ReferenceTargets;
use Xivi\Order\OrderModule;

/**
 * What a set of records naming other records costs (XIV-54).
 *
 * **The measurement is the feature.** Every other test here asks what a page
 * says; these ask how many statements it took to say it, because an N+1 is
 * invisible to the first kind of question — the invoice still names all 500 of
 * its articles, just 500 lookups later. `findChildren()` has no LIMIT (that
 * ceiling is XIV-68 and deliberately not touched here), so the number of rows on
 * a record page is whatever the customer typed, and "one query per row" is the
 * shape that stops working at a size nobody tests by hand.
 *
 * The order module is the subject rather than a fixture invented here, because
 * it is the real case: a line names an article through a reference, its
 * description and price were copied off that article and the page checks whether
 * they have since drifted (XIV-18) — so a row asks *twice* about the same
 * record, and both asks used to be a query.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ReferencePrimingTest extends WebTestCase
{
    use MeasuresQueries;
    use SharesATenant;

    private const string SLUG = 'test_priming';
    private const string HOST = 'priming.localhost';
    private const string EMAIL = 'priming@example.test';
    private const string MEMBER = 'member@priming.test';
    private const string PASSWORD = 'priming-password';

    /**
     * The two sizes every count here is taken at.
     *
     * Ten times as many rows, each naming an article of its own, so a lookup per
     * row would show up as a difference of 45 and nothing else would move. Small
     * enough that the fixtures are cheap and large enough that no plausible
     * fixed cost hides it.
     */
    private const int FEW = 5;
    private const int MANY = 50;

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

            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::EMAIL, 'Priming', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);

        $this->signIn(self::EMAIL);
    }

    /**
     * The acceptance criterion, in one assertion: ten times the rows, the same
     * number of queries.
     *
     * Deliberately `assertSame` rather than "fewer than N": a bound that grows
     * with the collection is the bug, so the test that matters is the one that
     * fails the moment the count starts to move at all. The two pages are
     * otherwise identical — same module, same shape, same template — so anything
     * left in the difference is per row.
     */
    public function testARecordPageCostsTheSameQueriesHoweverManyRowsItHas(): void
    {
        $few = $this->anOrderOfDistinctArticles(self::FEW);
        $many = $this->anOrderOfDistinctArticles(self::MANY);

        $small = $this->queriesToShow($few);
        $large = $this->queriesToShow($many);

        self::assertSame($small, $large, sprintf(
            'a record page is bounded: %d rows cost %d queries, %d rows cost %d',
            self::FEW,
            $small,
            self::MANY,
            $large,
        ));
    }

    /**
     * The document path draws the same rows and gets the same treatment.
     *
     * Measured on the block expansion itself rather than through the download
     * route, which would drag a template upload and a PDF converter into a test
     * about a number of queries. It is the same call the route makes, and it is
     * where the rows are read.
     */
    public function testExpandingADocumentsRowsIsBoundedToo(): void
    {
        $few = $this->anOrderOfDistinctArticles(self::FEW);
        $many = $this->anOrderOfDistinctArticles(self::MANY);

        $small = $this->queriesToExpand($few);
        $large = $this->queriesToExpand($many);

        self::assertSame($small, $large, sprintf(
            'a repeated block is bounded: %d rows cost %d queries, %d rows cost %d',
            self::FEW,
            $small,
            self::MANY,
            $large,
        ));
    }

    /**
     * Priming is an optimisation, never a requirement.
     *
     * The property the whole seam is built around: a caller that does not prime
     * gets the same names out of the same memo, one lookup at a time. This is
     * the test that keeps it true — a future version that only worked when
     * somebody remembered to prime would pass every other test in this file and
     * break silently the first time a reference was rendered from somewhere new.
     */
    public function testACallerThatDoesNotPrimeIsStillRightAndOnlySlower(): void
    {
        $order = $this->anOrderOfDistinctArticles(self::FEW);

        [$cold, $unprimed] = $this->namesOfLines($order, prime: false);
        [$warm, $primed] = $this->namesOfLines($order, prime: true);

        self::assertSame($cold, $warm, 'the same names either way, which is the part that must not move');
        self::assertCount(self::FEW, $warm);
        self::assertSame(1, $primed, 'primed: one statement for every article on the page');
        self::assertSame(self::FEW, $unprimed, 'unprimed: one per row, correct and slower');
    }

    /**
     * Priming reads under exactly the rule `titleOf()` already read under: the
     * name unscoped, the link scoped (§8.4, XIV-42).
     *
     * Worth its own test because a batched read is where a permission quietly
     * changes. Widening would be invisible — every name already showed — and
     * narrowing would show `#14` where an article used to be. Somebody with no
     * grant on articles at all is the sharpest version of both: they may read
     * this order, so they may read what its lines are for, and they may not open
     * the articles themselves.
     */
    public function testAPrimedNameObeysTheSameRuleAnUnprimedOneDid(): void
    {
        $order = $this->anOrderOfDistinctArticles(self::FEW);
        $names = $this->articleNames();

        $this->grant(self::MEMBER, OrderModule::KEY, ModuleAction::View);
        $this->grant(self::MEMBER, OrderModule::KEY, ModuleAction::List);
        $this->signIn(self::MEMBER);

        $page = $this->client->request('GET', $this->url('/m/order/' . $order));
        $text = $page->filter('main')->text();

        foreach ($names as $name) {
            self::assertStringContainsString($name, $text, 'the name of a linked record is read unscoped');
        }

        self::assertCount(
            0,
            $page->filter('main a[href*="/m/article/"]'),
            'and no door is offered to a module this reader has no grant on',
        );
    }

    /**
     * The memo dies with the request (§7.4).
     *
     * Proved by asking for the same page twice rather than by poking the
     * service: if anything survived the first request the second would be
     * cheaper, because every article on it was already in hand. The counts being
     * equal is the whole assertion, and it is an assertion about Symfony's
     * services resetter doing on `kernel.terminate` what the `kernel.reset` tag
     * — put there by autoconfiguration, because these memos implement
     * `ResetInterface` — asked it to.
     *
     * That matters beyond tidiness. A memo of one customer's records outliving
     * the request would eventually name their articles on somebody else's page,
     * which reads as a wrong label rather than as an error and is therefore the
     * kind of bug that ships.
     */
    public function testTheMemoDoesNotOutliveTheRequest(): void
    {
        $order = $this->anOrderOfDistinctArticles(self::FEW);

        // Warm whatever is warmed once per process rather than once per request,
        // so that what is compared below is two requests in the same state.
        $this->queriesToShow($order);

        self::assertSame(
            $this->queriesToShow($order),
            $this->queriesToShow($order),
            'the same page costs the same twice: nothing it read was still in memory',
        );
    }

    // -- measurements -------------------------------------------------------

    /** Statements against the tenant database while the record page is rendered. */
    private function queriesToShow(int $order): int
    {
        [, $count] = self::countingQueries(function () use ($order): void {
            $this->client->request('GET', $this->url('/m/order/' . $order));
            self::assertResponseIsSuccessful();
        });

        return $count;
    }

    /** The same, for the rows a .docx template repeats. */
    private function queriesToExpand(int $order): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($order): int {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $order);
            self::assertNotNull($record);

            $path = self::aDocxRepeating(['[lines.article]', '[lines.description]']);

            [, $count] = self::countingQueries(function () use ($path, $module, $record): void {
                self::service(RepeatingBlocks::class)->expand($path, $module, $record);
            });

            self::assertStringContainsString(
                'Article 1',
                (string) self::textOf($path),
                'the rows really were drawn, so the count is about something',
            );

            @unlink($path);

            return $count;
        });
    }

    /**
     * The names a page would print for an order's lines, and what they cost.
     *
     * Rendered through the field type exactly as the template does — one value
     * at a time, which is the point: nothing during rendering knows the whole
     * set, so priming has to have happened before this loop starts.
     *
     * @return array{list<string>, int}
     */
    private function namesOfLines(int $order, bool $prime): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($order, $prime): array {
            $collection = self::lines();
            $lines = $this->linesOf($order);

            $field = $collection->getField('article');
            self::assertNotNull($field);

            $type = self::service(FieldTypeRegistry::class)->get($field->getType());

            // Everything that is read once per request and is not the subject —
            // the target module's own definitions — is read before the counting
            // starts, so the number left is the lookups themselves.
            self::service(MetadataRepository::class)->get(ArticleModule::KEY);

            // And a memo left over from building the fixtures, or from another
            // test in this class, would answer for free and measure nothing.
            self::service(ReferenceTargets::class)->reset();
            self::service(ReferenceFieldType::class)->reset();

            return self::countingQueries(function () use ($collection, $lines, $field, $type, $prime): array {
                if ($prime) {
                    self::service(RecordPrimer::class)->prime($collection, $lines);
                }

                return array_map(
                    static fn (Record $line): string => $type->display($line->get('article'), $field),
                    $lines,
                );
            });
        });
    }

    // -- fixtures -----------------------------------------------------------

    /**
     * An order of `$rows` lines, each selling an article of its own.
     *
     * Distinct articles on purpose: rows all naming the same one would be
     * bounded by the memo whatever this ticket did, and would prove nothing.
     * Written through the writer rather than the form component, because the
     * subject here is a page with fifty rows on it and building those through
     * fifty live-component saves would be a fixture slower than the suite.
     */
    private function anOrderOfDistinctArticles(int $rows): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($rows): int {
            $metadata = self::service(MetadataRepository::class);
            $writer = self::service(RecordWriter::class);

            $contacts = $metadata->get(ContactModule::KEY);
            $articles = $metadata->get(ArticleModule::KEY);
            $orders = $metadata->get(OrderModule::KEY);

            $customer = $writer->save($contacts, new Record(data: [
                'kind' => ContactModule::COMPANY,
                'company_name' => 'Acme AG',
            ]));

            $lines = [];

            for ($n = 1; $n <= $rows; ++$n) {
                $article = $writer->save($articles, new Record(data: [
                    'title' => sprintf('Article %d', $n),
                    'price' => '19.90',
                ]));

                $lines[] = ['id' => null, 'data' => [
                    OrderModule::KIND => OrderModule::ARTICLE_LINE,
                    'article' => (int) $article->id,
                    'description' => sprintf('Article %d', $n),
                    'quantity' => '1',
                    'unit_price' => '19.90',
                ]];
            }

            $order = $writer->save(
                $orders,
                new Record(data: [
                    'contact' => (int) $customer->id,
                    'ordered_on' => '2026-08-15',
                    'status' => OrderModule::DRAFT,
                ]),
                [OrderModule::LINES => $lines],
            );

            return (int) $order->id;
        });
    }

    /** @return list<string> */
    private function articleNames(): array
    {
        return array_map(
            static fn (int $n): string => sprintf('Article %d', $n),
            range(1, self::FEW),
        );
    }

    /** @return list<Record> */
    private function linesOf(int $order): array
    {
        return self::service(RecordRepository::class)->findChildren(self::lines(), $order);
    }

    private static function lines(): CollectionDefinition
    {
        $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
        \assert($module instanceof ModuleDefinition);

        $lines = $module->getCollection(OrderModule::LINES);
        self::assertNotNull($lines);

        return $lines;
    }

    /**
     * The smallest .docx with one repeating row in it.
     *
     * Three parts is all Word needs to open a file, and this is a copy of what
     * RepeatingBlockTest builds — kept local rather than shared, because that
     * test's version grows options for the cases it is about and this one wants
     * to stay the one row it measures.
     *
     * @param list<string> $cells
     */
    private static function aDocxRepeating(array $cells): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-priming-') . '.docx';

        $row = '<w:tr>';

        foreach ($cells as $cell) {
            $row .= '<w:tc><w:p><w:r><w:t xml:space="preserve">'
                . htmlspecialchars($cell, \ENT_XML1)
                . '</w:t></w:r></w:p></w:tc>';
        }

        $row .= '</w:tr>';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:tbl>' . $row . '</w:tbl>'
            . '</w:body></w:document>');
        $zip->close();

        return $path;
    }

    /** What the expanded document says, with the markup taken off. */
    private static function textOf(string $path): string
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true, 'the document opens');

        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return strip_tags(str_replace(['</w:p>', '</w:tc>', '</w:tr>'], ' ', $xml));
    }

    private function grant(string $email, string $module, ModuleAction $action): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email, $module, $action): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            $manager->persist(PermissionGrant::forUser($user, $module, $action, PermissionScope::All));
            $manager->flush();
        });
    }

    private function signIn(string $email): void
    {
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
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
