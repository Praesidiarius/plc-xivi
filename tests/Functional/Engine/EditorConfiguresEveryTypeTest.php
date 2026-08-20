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

use App\Controller\FieldController;
use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use App\Tests\Support\UnaskableType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\NeedsAnAnswer;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * The editor does not offer a field type it cannot configure (XIV-144), and
 * every type's form asks that type's own questions ([XIV-163]).
 *
 * **The test XIV-144 exists for**, and it is deliberately about the *registry*
 * rather than about today's list of types. The defect was not that `choice` and
 * `reference` were forgotten; it was that nothing anywhere compared the types a
 * customer may add with the settings the editor can ask about, so two of them
 * drifted apart silently and the eleventh field type would have drifted the same
 * way.
 *
 * Three parts, and all three are needed:
 *
 *  * **the invariant**, over every type the container actually registers: what
 *    the type says it cannot work without is something this editor draws a
 *    control for. That is what goes red when somebody writes a twelfth type and
 *    does not add its line;
 *  * **the planted violation**, because an invariant that has never been seen to
 *    fail is an invariant nobody knows is connected to anything, which is the
 *    lesson deptrac taught this project when every layer in it collected nothing
 *    for four months (XIV-60). A type that needs an answer nobody drew is
 *    defined here and offered to the same rule, which refuses it;
 *  * **the rendered surface**, which is [XIV-163]'s addition. Until this ticket
 *    the whole editor was one form, so "the editor draws a control for it" could
 *    be checked against a declaration and left there. Now each type has a form of
 *    its own, and the claim worth guarding is the stronger one: open the add form
 *    for every type there is, and find on it a control named after every answer
 *    that type says it cannot work without. A declaration that agreed with itself
 *    while the page drew nothing would be XIV-144's defect with better paperwork.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class EditorConfiguresEveryTypeTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_editor_types';
    private const string HOST = 'editortypes.localhost';
    private const string ADMIN = 'admin@editortypes.test';
    private const string PASSWORD = 'types-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $tenant = $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));

        self::service(UserCreator::class)->create($tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
    }

    /**
     * Every registered type is one the editor can ask the customer's question
     * for.
     *
     * The message names what to do about it, because whoever this fails on is
     * somebody who has just written a field type and is about to conclude the
     * test is wrong.
     */
    public function testTheEditorCanConfigureEveryRegisteredFieldType(): void
    {
        foreach ($this->registry()->all() as $key => $type) {
            self::assertTrue(FieldController::configurable($type), sprintf(
                'The field type "%s" says it needs %s, and the metadata editor draws no control for %s. '
                .'Add the option to FieldController::PER_TYPE with the capability interface that declares '
                .'it, and a control to templates/field/_type_options.html.twig. Without both, the type '
                .'cannot be added by a customer at all (docs/architecture/data-model.md §5.4).',
                $key,
                implode(', ', self::optionsOf($type)),
                implode(', ', array_diff(
                    self::optionsOf($type),
                    array_keys(FieldController::PER_TYPE),
                )) ?: 'one of them',
            ));
        }
    }

    /**
     * And the form for that type actually draws them ([XIV-163]).
     *
     * The rendered half, and the one that survives the restructure. Before this
     * ticket there was one form and the question was which of its controls to
     * show; now there is a form per type and the question is what that form
     * contains at all, which is a thing only the page can answer. So the page is
     * opened, for every type the editor offers, and asked.
     *
     * Named after the option rather than found by shape, because the name is the
     * contract: it is the string the type declares, the key
     * {@see FieldController::PER_TYPE} is keyed by, and the request parameter the
     * controller reads back. A control that is not named after the option would
     * be a control the save cannot see.
     */
    public function testEveryTypesOwnFormDrawsEveryAnswerItsTypeNeeds(): void
    {
        $this->signIn();
        $shape = $this->shapeId();

        foreach ($this->registry()->all() as $key => $type) {
            if (!FieldController::configurable($type)) {
                // Not offered at all, which is the rule above and is a different
                // statement from this one. Today no type is in this state.
                continue;
            }

            $crawler = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/add/%s', $shape, $key)));

            self::assertResponseIsSuccessful(sprintf('the add form for "%s" opens', $key));

            foreach (($type instanceof NeedsAnAnswer ? $type->needs() : []) as $answers) {
                foreach ($answers as $option) {
                    self::assertGreaterThan(
                        0,
                        $crawler->filter(sprintf('[name="%s"]', $option))->count(),
                        sprintf(
                            'A "%s" field cannot work without "%s", and its own add form has no control named that. '
                            .'Add one to templates/field/_type_options.html.twig (§5.4).',
                            $key,
                            $option,
                        ),
                    );
                }
            }
        }
    }

    /**
     * And nothing else, which is the acceptance criterion [XIV-163] is named
     * after.
     *
     * "Only that type's options" is not a nicety about page length. A control
     * beside a field it means nothing on is one somebody fills in and waits for
     * something to happen, which is the defect XIV-144 is named after wearing a
     * hat. The old combined form had to draw every type's options at once, so it
     * could only defend against that with conditions nobody could read.
     * Here the defence is structural, and this is what says so.
     */
    public function testATypesOwnFormDrawsNoOtherTypesOptions(): void
    {
        $this->signIn();
        $shape = $this->shapeId();

        foreach ($this->registry()->all() as $key => $type) {
            if (!FieldController::configurable($type)) {
                continue;
            }

            $crawler = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/add/%s', $shape, $key)));
            $mine = FieldController::optionsOf($type);

            foreach (array_keys(FieldController::PER_TYPE) as $option) {
                if (\in_array($option, $mine, true)) {
                    continue;
                }

                self::assertCount(0, $crawler->filter(sprintf('[name="%s"]', $option)), sprintf(
                    'The add form for "%s" draws a control for "%s", which no %s field has.',
                    $key,
                    $option,
                    $key,
                ));
            }
        }
    }

    /**
     * The list of types is the registry, filtered by the rule above.
     *
     * The page's half of the invariant: that one proves what `configurable()`
     * says, this one proves the page is built from it rather than from `all()`,
     * which is the line XIV-144's defect was hiding behind.
     */
    public function testTheTypeListOffersEveryTypeTheEditorCanConfigure(): void
    {
        $this->signIn();
        $shape = $this->shapeId();

        $crawler = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/add', $shape)));

        self::assertResponseIsSuccessful();

        $offered = array_map(
            static fn (string $href): string => substr($href, (int) strrpos($href, '/') + 1),
            $crawler->filter('main a[href*="/add/"]')->extract(['href']),
        );
        $configurable = array_keys(array_filter($this->registry()->all(), FieldController::configurable(...)));

        sort($offered);
        sort($configurable);

        self::assertSame($configurable, $offered, 'the type list is the registry filtered by that rule and by nothing else');
        self::assertContains('choice', $offered, 'and it is not empty by accident');
    }

    /**
     * And the two XIV-144 was about are configurable *because* of their
     * capability, not by accident.
     *
     * Reads the declared list rather than the rendered page: this is the wiring,
     * option to capability interface, and the page is tested where a customer
     * uses it ({@see FieldChoicesUiTest}).
     */
    public function testTheTwoOptionsThisTicketAddedAreWiredToACapability(): void
    {
        self::assertArrayHasKey(ChoiceFieldType::CHOICES, FieldController::PER_TYPE);
        self::assertArrayHasKey(ReferenceFieldType::MODULE, FieldController::PER_TYPE);

        $registry = $this->registry();
        $choice = $registry->get('choice');
        $reference = $registry->get('reference');

        self::assertInstanceOf(NeedsAnAnswer::class, $choice);
        self::assertInstanceOf(NeedsAnAnswer::class, $reference);
        // One question each, and the choice field's has **two** answers since
        // [XIV-127]: its own options, or a shared list it is pointed at. Written
        // out rather than flattened, because the nesting is the statement. A
        // flat list would say a choice field needs both, which would refuse every
        // definition in every tenant.
        self::assertSame([[ChoiceFieldType::CHOICES, ChoiceFieldType::LIST]], $choice->needs());
        self::assertSame([[ReferenceFieldType::MODULE]], $reference->needs());
    }

    /**
     * Every way of answering is drawable, not merely one of them ([XIV-127]).
     *
     * The planted violation for the alternation itself, and the one XIV-127 could
     * most plausibly have broken: a type offering two answers of which the editor
     * can only ask for one *is* finishable through the form, so a laxer rule
     * would pass, and the second answer would be unreachable from the only screen
     * there is, which is XIV-144's silent gap one level in.
     */
    public function testATypeWhoseSecondAnswerNobodyDrewIsNotOfferedForAdding(): void
    {
        self::assertFalse(
            FieldController::configurable(new class extends UnaskableType {
                public function needs(): array
                {
                    // The first answer is drawn and the second is not, which is
                    // exactly the shape a `choice` field would have had if
                    // PER_TYPE had never learned about shared lists.
                    return [[ChoiceFieldType::CHOICES, 'bucket']];
                }
            }),
        );
    }

    /**
     * A type needing something nobody built a control for is refused by the same
     * rule.
     *
     * The planted violation. It is not registered in the container, because a
     * test that altered the container would be testing a container nobody runs,
     * and the rule it is fed to is a pure function of the type and the declared
     * list, which is exactly why that function is public and static.
     */
    public function testATypeNeedingSomethingNobodyDrewIsNotOfferedForAdding(): void
    {
        self::assertFalse(
            FieldController::configurable(new class extends UnaskableType {
                public function needs(): array
                {
                    // An option no capability in PER_TYPE is keyed by, which is
                    // what "somebody wrote a field type and no control for it"
                    // looks like from here.
                    return [['bucket']];
                }
            }),
            'a type whose need nothing draws must not be offered when a field is being added',
        );
    }

    /**
     * And a type that needs nothing is offered, which is what stops the rule
     * above from being "no new types allowed".
     */
    public function testATypeThatNeedsNothingIsOffered(): void
    {
        $text = $this->registry()->get('text');

        self::assertNotInstanceOf(NeedsAnAnswer::class, $text);
        self::assertTrue(FieldController::configurable($text));
    }

    /**
     * Every option any type names, flattened, for the message above.
     *
     * @return list<string>
     */
    private static function optionsOf(FieldType $type): array
    {
        return $type instanceof NeedsAnAnswer ? array_merge(...$type->needs()) : [];
    }

    private function registry(): FieldTypeRegistry
    {
        return self::service(FieldTypeRegistry::class);
    }

    /** The contact module's own shape, which every add form hangs off. */
    private function shapeId(): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => (int) self::service(MetadataRepository::class)->get(ContactModule::KEY)->getId(),
        );
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::ADMIN,
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
