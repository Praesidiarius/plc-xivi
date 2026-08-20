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
use App\Tests\Support\FormattingLocales;
use App\Tests\Support\SharesATenant;
use App\Tests\Support\UnaskableType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Xivi\Contact\ContactModule;
use Xivi\Core\Demo\DemoDataGenerator;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\NeedsAnAnswer;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Form\RecordType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * A value survives being written down, shown to somebody and read back, in
 * every language this installation offers (XIV-45).
 *
 * ## The defect this is the answer to
 *
 * XIV-44 was a Critical bug that four hundred and eighty tests walked past, the
 * browser layer included, and the reason is one sentence: **the whole suite
 * spoke English, and in English a number's displayed form and its stored form
 * are the same string.** `19.90` on the screen, `19.90` in the database, so code
 * that confused the two could not be caught by any of it. In German they are
 * `19,90` and `19.90`, and the confusion blanked every total on the page.
 *
 * Making the suite German would move that blind spot rather than close it:
 * English has failure modes German does not, starting with a decimal point that
 * a German-only suite would never exercise once.
 *
 * ## What is checked, and why it is a property of a field type
 *
 * The bug class is narrow and nameable: **a value that crosses between what the
 * model stores and what the reader sees.** A field type owns its storage, its
 * form type and its display, so that crossing happens inside one class, and the
 * round trip is therefore a property of a field type rather than of the four
 * hundred tests that happen to use one:
 *
 *   stored value → `fromStorage()` → the form's model data → **the view data,
 *   which is what the browser is shown** → submitted back exactly as shown →
 *   the form's model data again → `toStorage()` → the stored value.
 *
 * The middle step is the whole point. {@see self::submitted()} walks the form
 * tree and takes each leaf's *view* data, which is the array
 * `ComponentWithFormTrait` puts on `$this->formValues` and the array a `<form>`
 * posts. Reading those values as if they were model values is precisely what
 * XIV-44 did.
 *
 * **Over the registry, never over a list.** A type absent from here is a type
 * whose round trip nobody checks, and the way that happens is somebody writing
 * the next type without knowing this file exists. So the types come from
 * `FieldTypeRegistry::all()`, and each field is configured from what its own
 * type says it {@see NeedsAnAnswer needs}: a type asking a question this file
 * cannot answer fails by name, with the line to add.
 *
 * ## The locales
 *
 * From `%kernel.enabled_locales%`, expanded by {@see FormattingLocales} into one
 * locale per way of writing a figure, so the region half of §8.4.2 is covered
 * too: it is `fr_CH`, not `fr`, that writes plain numbers with a comma and money
 * with a point, and a suite checking four bare languages would have missed it.
 * Thirty locales today, and a fifth language adds its own handful rather than
 * multiplying anything.
 *
 * `\Locale::setDefault()` is how a locale is put in force here, because it is
 * how the application does it: `Request::setLocale()` sets the process default,
 * and every formatter of ours and every transformer of Symfony's reads it from
 * there.
 *
 * ## What this does not cover, said plainly
 *
 * The round trip is a property of a field type, so what is locale-dependent and
 * **not** a field type is outside it: a figure formatted in a Twig template or a
 * PDF, a sort order where `ä` lands differently, an ICU plural whose harder
 * forms English cannot exercise, and any consumer that reads view values without
 * going through a form. It also cannot see a type that stops localizing
 * altogether, because a value shown exactly as it is stored round-trips
 * perfectly; what pins the separators themselves is `SwissFiguresTest`.
 *
 * That last gap is XIV-44's own shape one level up, and it is covered at the
 * layer it lives on: {@see \App\Tests\Browser\FiguresInEveryLanguageTest}
 * drives the live totals through a real browser in each enabled language, and
 * `OrderTotalsTest` keeps XIV-44's own regression test.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FieldTypeRoundTripTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_round_trip';
    private const string HOST = 'roundtrip.localhost';
    private const string EMAIL = 'roundtrip@example.test';
    private const string PASSWORD = 'roundtrip-password';

    /**
     * Enough contacts for a `reference` field to have something real to point
     * at. More than one, so the id it holds is a choice rather than the only
     * possibility.
     */
    private const int CONTACTS = 3;

    /**
     * The one seed everything random here is drawn from, so a failure is a
     * failure again on the next run. Any other number would do.
     */
    private const int SEED = 20260820;

    /**
     * An answer to every question a type can ask, keyed by the **question**
     * rather than by the type.
     *
     * Keyed that way on purpose: a second type wanting to know what its values
     * are chosen between asks for `choices` like the first one, and is answered
     * here without this file learning its name. A new *question* costs one line,
     * and the failure that asks for it names the line.
     *
     * @var array<string, mixed>
     */
    private const array ANSWERS = [
        ChoiceFieldType::CHOICES => ['draft' => 'Draft', 'sent' => 'Sent', 'paid' => 'Paid'],
        ReferenceFieldType::MODULE => ContactModule::KEY,
    ];

    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        // A module with real records in it, because `reference` is a link to one
        // and a picker with nothing to pick from would round-trip an empty value
        // and report the type as covered.
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            );

            self::service(DemoDataGenerator::class)->generate(
                self::service(MetadataRepository::class)->get(ContactModule::KEY),
                self::CONTACTS,
                self::SEED,
            );
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Round Trip', self::PASSWORD, ['ROLE_ADMIN']);

        // **Somebody has to be reading**, and that is not a detail of the
        // harness. A `reference` is drawn as a picker, and a picker is scoped to
        // what the current user may see (§8.4, XIV-13), so with nobody signed in
        // the candidate list is empty, the stored id is not among the choices,
        // and the field shows the empty string. That would round-trip a link
        // into nothing and call the type covered. There is no request here to
        // carry a session, so the token goes straight into the storage the
        // security component reads.
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $user = self::service(UserRepository::class)->findOneByEmail(self::EMAIL);
            self::assertInstanceOf(User::class, $user);

            self::service(TokenStorageInterface::class)
                ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        });
    }

    /**
     * Every registered field type, in every locale, both ways.
     *
     * One form per locale rather than one per field, because a record form is a
     * whole shape and a form of one field would be an arrangement nobody
     * renders. The failures are still per field, so what fails names the type
     * that broke and the language it broke in.
     */
    public function testEveryFieldTypeSurvivesTheTripThroughEveryLanguage(): void
    {
        $types = $this->registry()->all();
        $failures = [];
        $crossings = 0;

        foreach ($this->locales() as $locale) {
            $trip = $this->roundTrip($types, $locale);

            $failures = [...$failures, ...$trip['failures']];
            $crossings += $trip['crossings'];
        }

        self::assertSame([], $failures, implode("\n", $failures));

        // **The corroboration, and it is not decoration.** Everything above
        // would also pass if the locale never changed, if the view value were
        // the stored value everywhere, or if this file quietly ended up
        // exercising English thirty times over: a round trip through the
        // identity function survives every time. So the sweep counts the fields
        // whose displayed form actually differed from their stored one and
        // refuses to be green without them. That is deptrac's lesson (XIV-60):
        // a check never seen to have anything to say is a check nobody knows is
        // connected to anything.
        self::assertGreaterThan(0, $crossings, sprintf(
            'Not one field in %d locales was shown differently from the way it is stored, so this test '
            . 'proved nothing: the round trip it checked was the identity function. Either '
            . '\\Locale::setDefault() has stopped reaching the form transformers, or the locale set has '
            . 'collapsed to a single way of writing a figure. See tests/Support/FormattingLocales.php.',
            \count($this->locales()),
        ));
    }

    /**
     * And the locale set is the enabled languages, not a handful of strings
     * somebody typed.
     *
     * The half of the acceptance criterion a green sweep cannot show: a sweep
     * over a set that had silently lost French would pass exactly as happily. So
     * the set is asked what it holds, and it has to hold every enabled language
     * and something region-qualified for each of them.
     */
    public function testTheLocaleSetFollowsTheEnabledLanguages(): void
    {
        $languages = $this->enabledLanguages();
        $locales = $this->locales();

        self::assertNotSame([], $languages, 'the enabled languages are readable at all');

        foreach ($languages as $language) {
            self::assertContains($language, $locales, sprintf(
                'the bare language "%s" is what an installation that has never chosen a region formats in',
                $language,
            ));

            self::assertNotSame(
                [],
                array_filter($locales, static fn (string $locale): bool => str_starts_with($locale, $language . '_')),
                sprintf(
                    'no region-qualified locale was derived for "%s", so the half of §8.4.2 that composes a '
                    . 'language with a country goes untested for it',
                    $language,
                ),
            );
        }
    }

    /**
     * The form this file builds is the form a record form builds.
     *
     * {@see self::form()} exists because the planted violation cannot be in the
     * container's registry, and it is worth exactly nothing if it has drifted
     * from `RecordType`: a round trip through a form nobody renders proves
     * something about a form nobody renders. So the same shape goes through
     * both, in a locale that writes figures differently from English, and what
     * the two would show a reader has to be the same array.
     */
    public function testThisHarnessBuildsTheFormARecordFormBuilds(): void
    {
        $types = $this->registry()->all();
        $locale = $this->firstLocaleWritingADecimalComma();

        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($types, $locale): void {
            $shape = $this->shape($types);
            $stored = $this->storedValues($shape, $types);

            $previous = \Locale::getDefault();
            \Locale::setDefault($locale);

            try {
                $model = [];

                foreach ($shape->getFieldsFor(null) as $field) {
                    $model[$field->getKey()] = $types[$field->getType()]->fromStorage($stored[$field->getKey()], $field);
                }

                $real = self::service(FormFactoryInterface::class)
                    ->create(RecordType::class, $model, ['shape' => $shape]);

                self::assertSame(
                    self::submitted($real),
                    self::submitted($this->form($shape, $types, $model)),
                    'the harness and RecordType have to show a reader the same thing, or this file is '
                    . 'round-tripping a form nobody renders',
                );
            } finally {
                \Locale::setDefault($previous);
            }
        });
    }

    /**
     * **XIV-44, planted.** A type that hands the form what the reader sees is
     * caught, and is caught only outside English.
     *
     * This is the demonstration the ticket asked for, kept rather than done once
     * and reverted, for the reason every planted violation in this suite is kept
     * (XIV-60): a rule nobody has watched refuse anything is a rule nobody knows
     * is connected to anything. The type below makes XIV-44's mistake in one
     * line, `fromStorage()` returning the value *formatted for the reader*
     * instead of the value the database holds, and everything downstream of it
     * is ordinary: a plain text box, and a `toStorage()` that expects the stored
     * spelling.
     *
     * The two assertions are the whole argument for this ticket existing:
     *
     *  * **in English it survives**, because there the formatted number and the
     *    stored number are the same string. That is the suite XIV-44 shipped
     *    through, reproduced;
     *  * **in the first locale writing a decimal comma it does not**, which is
     *    the same defect in the same code, meeting a language that tells the two
     *    spellings apart.
     *
     * The comma locale is *found* rather than named, so this is about "a
     * language that writes numbers differently" and not about German, and it
     * keeps working when the enabled set changes.
     */
    public function testATypeThatHandsTheFormWhatItShowedIsCaughtOutsideEnglish(): void
    {
        $planted = ['planted' => $this->plantedType()];

        self::assertSame(
            [],
            $this->roundTrip($planted, 'en')['failures'],
            'XIV-44 survives an English suite untouched, which is the whole reason this file exists',
        );

        $comma = $this->firstLocaleWritingADecimalComma();

        self::assertNotSame(
            [],
            $this->roundTrip($planted, $comma)['failures'],
            sprintf('and the same defect has to be refused in %s, where the two spellings differ', $comma),
        );
    }

    /**
     * One locale's sweep: build the form, read what it shows, send that back,
     * and compare what would be stored.
     *
     * Collects rather than asserts, because the planted violation above has to
     * ask this same machinery for a *failure* and read it as a pass. The
     * messages are written here, where the values are.
     *
     * @param array<string, FieldType> $types
     *
     * @return array{failures: list<string>, crossings: int}
     */
    private function roundTrip(array $types, string $locale): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($types, $locale): array {
            $shape = $this->shape($types);
            $stored = $this->storedValues($shape, $types);

            $previous = \Locale::getDefault();

            // **Before the model values are read out of storage**, not after.
            // Everything from here to the end of the trip is one reader's page,
            // and `fromStorage()` is part of it: a type that formats where it
            // should have fetched does it there, which is exactly XIV-44's line.
            // Setting the locale afterwards would run that step in whatever
            // language the previous test left behind, and the sweep would find
            // English hiding inside every locale.
            \Locale::setDefault($locale);

            try {
                $model = [];

                foreach ($shape->getFieldsFor(null) as $field) {
                    $model[$field->getKey()] = $types[$field->getType()]->fromStorage($stored[$field->getKey()], $field);
                }

                $shown = self::submitted($this->form($shape, $types, $model));

                $returned = $this->form($shape, $types, null);
                $returned->submit($shown);

                /** @var array<string, mixed> $back */
                $back = $returned->getData();

                $failures = [];
                $crossings = 0;

                foreach ($shape->getFieldsFor(null) as $field) {
                    $key = $field->getKey();
                    $again = $types[$field->getType()]->toStorage($back[$key] ?? null, $field);

                    if ($again !== $stored[$key]) {
                        $failures[] = sprintf(
                            'A "%s" field does not survive being read in %s. It is stored as %s, shown as %s, '
                            . 'and what came back would be stored as %s. Whatever converts between the two '
                            . 'directions disagrees with itself in this language (XIV-45, XIV-44).',
                            $field->getType(),
                            $locale,
                            var_export($stored[$key], true),
                            var_export($shown[$key] ?? null, true),
                            var_export($again, true),
                        );
                    }

                    // What the reader saw was not what the database holds, which
                    // is the crossing this whole file is about having witnessed.
                    if (\is_string($shown[$key] ?? null) && $shown[$key] !== $stored[$key]) {
                        ++$crossings;
                    }
                }

                return ['failures' => $failures, 'crossings' => $crossings];
            } finally {
                \Locale::setDefault($previous);
            }
        });
    }

    /**
     * A shape with one field per type, built in memory.
     *
     * Not written into the tenant's definitions, because nothing here saves a
     * record: `RecordType` is handed a shape and asks each field's type for its
     * form, which is the entire seam under test. Persisting the definitions
     * would buy a slower test and the same answer.
     *
     * @param array<string, FieldType> $types
     */
    private function shape(array $types): ModuleDefinition
    {
        $shape = new ModuleDefinition('round_trip', 'Round trip', 'round_trip_records');

        foreach ($types as $key => $type) {
            $field = new FieldDefinition($shape, $key . '_field', ucfirst($key), $key);

            // Required, so a type whose `sample()` leaves optional fields empty
            // one time in ten hands back a value every run. An empty field
            // round-trips through anything, and a covered type that is not
            // covered is the thing this file is against.
            $field->setRequired(true);
            $field->setOptions($this->answersFor($key, $type));

            $shape->addField($field);
        }

        return $shape;
    }

    /**
     * What each field would hold in the database.
     *
     * The values come from the types themselves. `sample()` is a type's own
     * statement of what a plausible value of its kind looks like (§5.17), so the
     * type written tomorrow is round-tripped against a value it chose rather
     * than against whatever this file could have guessed for it, and the guess
     * is exactly the thing that would have gone stale.
     *
     * @param array<string, FieldType> $types
     *
     * @return array<string, mixed>
     */
    private function storedValues(ModuleDefinition $shape, array $types): array
    {
        mt_srand(self::SEED);

        $stored = [];

        foreach ($shape->getFieldsFor(null) as $field) {
            $type = $types[$field->getType()];
            $value = $type->toStorage($type->sample($field, 1), $field);

            self::assertNotNull($value, sprintf(
                'The "%s" type produced no sample value, so its round trip would be a trip taken by '
                . 'nothing. A required field must not sample as empty (§5.17).',
                $field->getType(),
            ));

            $stored[$field->getKey()] = $value;
        }

        return $stored;
    }

    /**
     * The answers a type cannot work without, taken from {@see self::ANSWERS}.
     *
     * A type may offer several ways of being answered ({@see NeedsAnAnswer}),
     * and the first one this file can supply is the one used, which mirrors how
     * a customer finishes such a field: give it its own options *or* point it at
     * a shared list, never both.
     *
     * @return array<string, mixed>
     */
    private function answersFor(string $key, FieldType $type): array
    {
        $options = [];

        foreach ($type instanceof NeedsAnAnswer ? $type->needs() : [] as $question) {
            $answered = false;

            foreach ($question as $option) {
                if (!\array_key_exists($option, self::ANSWERS)) {
                    continue;
                }

                $options[$option] = self::ANSWERS[$option];
                $answered = true;

                break;
            }

            self::assertTrue($answered, sprintf(
                'The field type "%s" cannot work without one of [%s], and this test can answer none of '
                . 'them, so its round trip cannot be built at all. Add an entry to '
                . 'FieldTypeRoundTripTest::ANSWERS keyed by the option name (§5.4).',
                $key,
                implode(', ', $question),
            ));
        }

        return $options;
    }

    /**
     * XIV-44 as a field type: what goes into the form is what the reader sees.
     *
     * Deliberately **not registered in the container**, for the reason
     * {@see UnaskableType} gives: a test that altered the container would be
     * testing a container nobody runs. It is handed to the same
     * {@see self::roundTrip()} every real type goes through, so what refuses it
     * is the rule rather than a copy of the rule.
     */
    private function plantedType(): FieldType
    {
        return new class extends UnaskableType {
            public function key(): string
            {
                return 'planted';
            }

            public function needs(): array
            {
                return [];
            }

            /**
             * **The defect, in three lines.** The database holds `19.90`; this
             * hands the form `19,90` for a German reader, because it reached for
             * a formatter where it wanted the stored value. Everything after it
             * is blameless, and everything after it is wrong.
             */
            public function fromStorage(mixed $value, FieldDefinition $field): string
            {
                $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL);
                $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 2);

                return $formatter->format((float) $value) ?: (string) $value;
            }

            /** Ordinary, and the same rule `DecimalFieldType` follows. */
            public function toStorage(mixed $value, FieldDefinition $field): mixed
            {
                return is_numeric($value) ? number_format((float) $value, 2, '.', '') : $value;
            }

            /** A plain box, so nothing between here and the screen converts anything. */
            public function formType(): string
            {
                return TextType::class;
            }

            public function sample(FieldDefinition $field, int $sequence): string
            {
                return '19.90';
            }
        };
    }

    /**
     * The first locale in the set that tells a displayed number from a stored
     * one.
     *
     * Found rather than named, so the planted violation above is about "a
     * language that writes numbers differently" and not about German. It fails
     * loudly if there is no such locale, which would mean the enabled set had
     * become one that cannot see XIV-44 at all: the exact state this ticket was
     * opened to leave behind.
     */
    private function firstLocaleWritingADecimalComma(): string
    {
        foreach ($this->locales() as $locale) {
            $separator = new \NumberFormatter($locale, \NumberFormatter::DECIMAL)
                ->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

            if ($separator !== '.') {
                return $locale;
            }
        }

        self::fail(
            'Every enabled locale writes a decimal point, so nothing in this suite can tell a displayed '
            . 'number from a stored one and XIV-44 could ship again unseen. That is what XIV-45 exists to '
            . 'prevent: see docs/architecture/decisions.md §9.2.',
        );
    }

    /**
     * The shape's fields as a form, exactly as `RecordType` assembles them.
     *
     * **Why this is not `RecordType` itself**, which would be the obvious
     * choice: that type takes its field types from the container's registry,
     * and the planted violation below is deliberately not in the container.
     * Symfony resolves a form type by name, so there is no way to hand
     * `RecordType` a different registry through the factory. Having the plant
     * travel a second path would prove the rule catches it on a road no value
     * ever takes, so every trip goes through these three lines instead, and they
     * are `RecordType::buildForm()`'s three lines: the type's own form type, the
     * type's own options for this field, and no validation, because
     * `RecordValidator` owns that.
     *
     * {@see self::testThisHarnessBuildsTheFormARecordFormBuilds()} is what stops
     * that from being a claim: it puts the same shape through both and compares
     * what the two show.
     *
     * @param array<string, FieldType>  $types
     * @param array<string, mixed>|null $data
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function form(ModuleDefinition $shape, array $types, ?array $data): FormInterface
    {
        $builder = self::service(FormFactoryInterface::class)->createBuilder(FormType::class, $data, [
            'data_class' => null,
            'validation_groups' => false,
        ]);

        foreach ($shape->getFieldsFor(null) as $field) {
            $type = $types[$field->getType()];

            $builder->add($field->getKey(), $type->formType(), [
                ...$type->formOptions($field),
                'label' => $field->getLabel(),
                'required' => $field->isRequired() && !$field->isDerived(),
                'constraints' => [],
                'disabled' => $field->isDerived(),
            ]);
        }

        return $builder->getForm();
    }

    /**
     * What a browser would send back, given this form as it stands.
     *
     * **The view data, at every leaf.** A leaf's view data is the string in its
     * input; a compound field's is spread across its children, which is why this
     * walks instead of reading one property, and why a period with two dates and
     * a tick is covered by the same three lines as a text box. This is the array
     * `ComponentWithFormTrait` puts on `$this->formValues`, and the array a
     * `<form>` posts.
     *
     * @param FormInterface<mixed> $form
     */
    private static function submitted(FormInterface $form): mixed
    {
        if (\count($form) === 0) {
            return $form->getViewData();
        }

        $values = [];

        foreach ($form as $name => $child) {
            $values[$name] = self::submitted($child);
        }

        return $values;
    }

    /** @return list<string> */
    private function locales(): array
    {
        return FormattingLocales::of($this->enabledLanguages());
    }

    /** @return list<string> */
    private function enabledLanguages(): array
    {
        /** @var list<string> $languages */
        $languages = self::getContainer()->getParameter('kernel.enabled_locales');

        return $languages;
    }

    private function registry(): FieldTypeRegistry
    {
        return self::service(FieldTypeRegistry::class);
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
