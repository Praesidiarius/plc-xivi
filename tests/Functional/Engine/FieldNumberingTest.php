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

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Numbering\CounterRefused;
use Xivi\Core\Numbering\NumberAllocator;
use Xivi\Core\Numbering\NumberFormat;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Order\OrderModule;

/**
 * A customer deciding what their own document numbers look like (XIV-27).
 *
 * XIV-15 built the mechanism and left this out, so the pattern was whatever the
 * blueprint seeded — which meant every Xivi customer's orders were called ORD-
 * whether they sold orders or Aufträge. What is under test here is the last step:
 * the pattern is theirs, the preview tells them what it will produce before they
 * commit to it, and the one control that can produce a duplicate number cannot.
 *
 * The duplicate is the one to read first. Two invoices carrying the same number
 * is a legal problem and not a cosmetic one, so the counter guard is tested three
 * ways: through the page, through the allocator itself, and by checking that the
 * number a record actually comes out with is the one the counter promised.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FieldNumberingTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_field_numbering';
    private const string HOST = 'numbering.localhost';
    private const string EMAIL = 'numbering@example.test';
    private const string ORDINARY = 'ordinary@example.test';
    private const string PASSWORD = 'numbering-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_HOST', self::HOST);

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $this->inTenant(function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            // Contact as well as order, and not only because an order needs one:
            // one of the claims is about a text field that is *not* numbered, and
            // a contact's name is the honest example of one.
            foreach ([ContactModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::EMAIL, 'Numbering', self::PASSWORD, ['ROLE_ADMIN']);
        // Somebody who works here and is not an administrator. Changing what a
        // module *is* is not one of the things you do to its records (§5.4), so
        // they have no grants at all and need none to make the point.
        $users->create($this->tenant, self::ORDINARY, 'Ordinary', self::PASSWORD, []);

        $this->signIn();
    }

    /** The link is on the field, and the page opens on the pattern in use. */
    public function testANumberedFieldOffersItsPatternInTheFieldEditor(): void
    {
        $fields = $this->client->request('GET', $this->url('/m/order/fields'));

        self::assertCount(
            1,
            $fields->filter('a[href$="/numbering"]'),
            'exactly one field on the module carries the link',
        );
        self::assertCount(
            1,
            $fields->filter('a[href$="/' . $this->fieldId(OrderModule::NUMBER) . '/numbering"]'),
            'and it is the numbered one',
        );

        $page = $this->numberingPage();

        self::assertSame('ORD-{year}-{number:4}', $page->filter('input[name="sequence"]')->attr('value'));
    }

    /**
     * The preview, which is why this is a page and not a text box in a table.
     *
     * Rendered from the pattern as typed, so somebody watches `RG-000001` appear
     * while they are still deciding how wide to make it — and a width too narrow
     * to sort correctly is visible rather than something they find out at the
     * hundredth invoice.
     */
    public function testThePreviewFollowsThePatternAsItIsTyped(): void
    {
        $component = $this->numbering();

        self::assertStringContainsString('ORD-' . date('Y') . '-0001', $this->markup($component));
        self::assertStringContainsString('RG-000001', $this->markup($component->set('pattern', 'RG-{number:6}')));
    }

    /**
     * A pattern with no counter is refused with a reason, on the page and on the
     * write path.
     *
     * The engine's own answer to such a pattern is silence — the field goes on
     * being an ordinary text field — which is right for a blueprint and wrong for
     * a form. Posted directly rather than through the button, deliberately: the
     * button is disabled, and what is being proved is that the *editor* refuses
     * rather than that the page hid the control.
     */
    public function testAPatternThatWouldNumberNothingIsRefused(): void
    {
        $markup = $this->markup($this->numbering()->set('pattern', 'ORD-{year}'));

        self::assertStringContainsString('would number nothing', $markup, 'said before it is saved');
        self::assertStringNotContainsString('The next number will be', $markup, 'and no number is offered');

        $this->save('ORD-{year}');

        self::assertStringContainsString(
            'needs {number} in it',
            $this->flash(),
            'and refused with the same reason when it is posted anyway',
        );
        self::assertSame('ORD-{year}-{number:4}', $this->pattern(), 'nothing was saved');
    }

    /**
     * Which counter the next number comes from, said in words.
     *
     * The two answers are different in kind rather than in wording: a pattern
     * with `{year}` has a counter per year that starts again each January, and
     * one without has a single counter that never does.
     */
    public function testThePageSaysWhichCounterTheNextNumberComesFrom(): void
    {
        self::assertStringContainsString(
            'counter for ' . date('Y'),
            $this->markup($this->numbering()),
        );

        self::assertStringContainsString(
            'never starts again',
            $this->markup($this->numbering()->set('pattern', 'ORD-{number:4}')),
        );
    }

    /**
     * The surprise, visible before it is saved.
     *
     * Dropping `{year}` moves to a counter that has never been used, so the next
     * order is 0001 again while the last one was 0087. That is defensible and
     * nobody would guess it, which is the definition of something a page has to
     * say out loud.
     */
    public function testSwitchingBetweenCountersIsVisibleBeforeItIsSaved(): void
    {
        $this->anOrder();
        $this->anOrder();

        $unchanged = $this->markup($this->numbering());
        self::assertStringNotContainsString('different counter', $unchanged, 'the pattern in use switches nothing');
        self::assertStringContainsString('ORD-' . date('Y') . '-0003', $unchanged, 'and carries on where it is');

        $dropped = $this->markup($this->numbering()->set('pattern', 'ORD-{number:4}'));

        self::assertStringContainsString('different counter', $dropped);
        self::assertStringContainsString('ORD-0001', $dropped, 'which starts at one');
    }

    /**
     * Changing the pattern changes the next number and touches no record.
     *
     * The whole promise of §5.10 in one test: numbers already given out are on
     * documents customers are holding, and nothing in the metadata editor may
     * reach them.
     */
    public function testChangingThePatternRenumbersNothing(): void
    {
        $first = $this->anOrder();
        $before = $this->numberOf($first);

        $this->save('AUF-{year}-{number:3}');

        self::assertSame($before, $this->numberOf($first), 'the order that was already numbered');
        self::assertSame(
            'AUF-' . date('Y') . '-002',
            $this->numberOf($this->anOrder()),
            'and the next one, from the same counter, in the new shape',
        );
    }

    /**
     * The reason the counter is settable at all: somebody arriving from another
     * system mid-sequence.
     *
     * Without this, numbering is a feature only a business on its first day of
     * trading can adopt.
     */
    public function testTheCounterCanBeSetToWhereAnotherSystemLeftOff(): void
    {
        $this->save('ORD-{year}-{number:4}', '1043');

        self::assertSame('ORD-' . date('Y') . '-1043', $this->numberOf($this->anOrder()));
        self::assertSame('ORD-' . date('Y') . '-1044', $this->numberOf($this->anOrder()), 'and on from there');
    }

    /** And it is refused where it would hand a number out twice. */
    public function testACounterCannotBeWoundBackOverNumbersAlreadyGivenOut(): void
    {
        $this->save('ORD-{year}-{number:4}', '1043');
        $used = $this->numberOf($this->anOrder());

        $this->save('ORD-{year}-{number:4}', '1000');
        $refusal = $this->flash();

        self::assertStringContainsString('counter for ' . date('Y'), $refusal);
        self::assertStringContainsString('1044', $refusal, 'and says where the counter stands');
        self::assertSame('ORD-' . date('Y') . '-1043', $used);
        self::assertSame(
            'ORD-' . date('Y') . '-1044',
            $this->numberOf($this->anOrder()),
            'the counter did not move, so 1043 cannot come round again',
        );
    }

    /**
     * The refusal is on the write path, not in the form.
     *
     * A guard that lived in the controller would hold on one screen and nowhere
     * else — and the thing it protects against is a duplicate invoice number,
     * which no apology fixes. So it is asked of the allocator directly here, the
     * way an import or a console command would meet it.
     */
    public function testTheGuardIsOnTheWritePathAndNotOnlyInTheForm(): void
    {
        $this->inTenant(function (): void {
            $counters = self::service(NumberAllocator::class);

            $counters->restartAt('probe', 'number', '2026', 500);
            self::assertSame(500, $counters->peek('probe', 'number', '2026'));

            $counters->restartAt('probe', 'number', '2026', 500);
            self::assertSame(500, $counters->peek('probe', 'number', '2026'), 'where it already is, is a no-op');

            self::assertSame(500, $counters->next('probe', 'number', '2026'), 'and the next number is the one set');
        });

        $this->inTenant(function (): void {
            $counters = self::service(NumberAllocator::class);

            try {
                $counters->restartAt('probe', 'number', '2026', 500);
                self::fail('a counter that has given out 500 must refuse to give it again');
            } catch (CounterRefused $refused) {
                self::assertStringContainsString('501', $refused->getMessage());
            }

            self::assertSame(501, $counters->peek('probe', 'number', '2026'), 'and nothing moved');
        });
    }

    /** Zero and below are the same refusal: a counter starts at one. */
    public function testACounterCannotBeSetBelowItsFirstNumber(): void
    {
        $this->inTenant(function (): void {
            $this->expectException(CounterRefused::class);

            self::service(NumberAllocator::class)->restartAt('probe', 'floor', '', 0);
        });
    }

    /**
     * A field that is not numbered has no numbering, and this is the scope valve
     * (§5.10).
     *
     * Making an ordinary text field numbered is a question about the records it
     * already holds — what happens to the ones with no number, and to the values
     * somebody typed by hand that a fresh counter knows nothing about — so it is
     * a separate feature rather than a control this page quietly grew. The link
     * is absent and the URL is not found; neither is an accident.
     */
    public function testAFieldThatIsNotNumberedHasNoNumberingPage(): void
    {
        $name = $this->fieldId('first_name', ContactModule::KEY);

        $fields = $this->client->request('GET', $this->url('/m/contact/fields'));
        self::assertCount(0, $fields->filter('a[href$="/' . $name . '/numbering"]'), 'no link on a plain text field');

        $this->client->request('GET', $this->url('/m/contact/fields/' . $name . '/numbering'));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The component asks who is looking, and not only the page it sits on.
     *
     * Props are signed rather than secret and a live component is reachable at
     * its own endpoint, so the `ROLE_ADMIN` on the route is not the only door
     * into this. Asking again costs one attribute check and removes the need to
     * reason about whether the page that mounts it is the only way in.
     */
    public function testTheComponentRefusesSomebodyWhoIsNotAnAdministrator(): void
    {
        $component = $this->numbering()->actingAs($this->userOf(self::ORDINARY));

        try {
            $component->render();
            self::fail('the component rendered for somebody who may not edit definitions');
        } catch (\Throwable $thrown) {
            // Through the chain rather than at the top, because the check fires
            // on the first accessor the template asks and Twig wraps whatever a
            // template threw. What is being asserted is the refusal, not the
            // wrapper it arrives in.
            $chain = [];

            for ($reason = $thrown; $reason !== null; $reason = $reason->getPrevious()) {
                $chain[] = $reason::class;
            }

            self::assertContains(AccessDeniedException::class, $chain, $thrown->getMessage());
        }
    }

    // -- helpers ------------------------------------------------------------

    /** The numbering component, mounted as its page mounts it. */
    private function numbering(): TestLiveComponent
    {
        // Read outside the switch below rather than inside it: each of these is
        // a tenant-scoped read of its own, and nesting one run inside another is
        // a way of asking which connection is current that has no good answer.
        $props = [
            'module' => OrderModule::KEY,
            'fieldId' => $this->fieldId(OrderModule::NUMBER),
            'pattern' => $this->pattern(),
        ];
        $admin = $this->admin();

        return $this->inTenant(fn (): TestLiveComponent => $this->createLiveComponent(
            'FieldNumbering',
            $props,
            $this->client,
        ))->actingAs($admin);
    }

    private function markup(TestLiveComponent $component): string
    {
        return $component->render()->toString();
    }

    /** The page a customer opens, for the parts that are not the component. */
    private function numberingPage(): Crawler
    {
        return $this->client->request(
            'GET',
            $this->url(sprintf('/m/order/fields/%d/numbering', $this->fieldId(OrderModule::NUMBER))),
        );
    }

    /**
     * Saves the numbering form the way somebody using it would.
     *
     * The form is submitted rather than posted, so a control that stopped being
     * drawn would fail here — but the *button* is deliberately not selected,
     * because it is disabled for a pattern that numbers nothing and one of the
     * tests is about posting exactly that.
     */
    private function save(string $pattern, string $next = ''): void
    {
        $form = $this->numberingPage()->filter('form[action$="/numbering"]')->form();
        $form[NumberFormat::OPTION] = $pattern;
        $form['next_value'] = $next;

        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    /** Whatever the last save had to say, as text. */
    private function flash(): string
    {
        return $this->client->followRedirect()->filter('main')->text();
    }

    private function anOrder(): int
    {
        return $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->savedId($this->saveRecord(
                ContactModule::KEY,
                ['kind' => 'company', 'company_name' => 'Acme AG'],
                variant: 'company',
            )),
            'ordered_on' => '2026-08-15',
            'status' => OrderModule::DRAFT,
        ]));
    }

    private function numberOf(int $order): string
    {
        return $this->inTenant(function () use ($order): string {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $order);
            self::assertInstanceOf(Record::class, $record);

            return (string) $record->get(OrderModule::NUMBER);
        });
    }

    /** The pattern the order's number field is stored with right now. */
    private function pattern(): string
    {
        return (string) $this->fieldOf(OrderModule::NUMBER, OrderModule::KEY)->getOption(NumberFormat::OPTION);
    }

    private function fieldId(string $key, string $module = OrderModule::KEY): int
    {
        return (int) $this->fieldOf($key, $module)->getId();
    }

    private function fieldOf(string $key, string $module): FieldDefinition
    {
        return $this->inTenant(function () use ($key, $module): FieldDefinition {
            $field = self::service(MetadataRepository::class)->get($module)->getField($key);
            self::assertInstanceOf(FieldDefinition::class, $field);

            return $field;
        });
    }

    /** Whoever the component acts as: an administrator, because the page is one. */
    private function admin(): User
    {
        return $this->userOf(self::EMAIL);
    }

    private function userOf(string $email): User
    {
        return $this->inTenant(function () use ($email): User {
            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            return $user;
        });
    }

    private function inTenant(callable $work): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, $work);
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
