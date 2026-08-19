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

namespace App\Tests\Unit\Field;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\PhoneFieldType;
use Xivi\Core\Phone\PhoneNumbers;
use Xivi\Core\Phone\PhoneProblem;
use Xivi\Core\Phone\PhoneRegion;
use Xivi\Core\Region\InstanceRegion;

/**
 * What a phone number *is*, once it is stored (XIV-114).
 *
 * A unit test because none of this needs a database: the whole claim is that one
 * number typed six ways produces one string. The claim that it produces that
 * string *everywhere* — the form, the importer, the query compiler, the unique
 * index — is not testable here and is not tested here; that is
 * {@see \App\Tests\Functional\Engine\PhoneNumberTest}, which goes through the
 * real paths. Asserting `toStorage()` and declaring the seam proved would be
 * testing the method rather than the seam.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PhoneFieldTypeTest extends TestCase
{
    /**
     * The ticket's opening sentence, as an assertion.
     *
     * Six spellings of one Swiss mobile, including the ones a phone's own
     * contacts app produces when it pastes.
     */
    public function testEveryCommonSpellingOfOneNumberStoresTheSameValue(): void
    {
        $type = $this->type();
        $field = $this->field();

        foreach ([
            '+41 79 123 45 67',
            '+41791234567',
            '079 123 45 67',
            '0791234567',
            '079/123 45 67',
            '  079 123 45 67  ',
        ] as $typed) {
            self::assertSame('+41791234567', $type->toStorage($typed, $field), $typed);
        }
    }

    /**
     * The same digits are a different number in a different country, which is
     * the whole reason a country has to be known at all.
     */
    public function testTheSameDigitsInAnotherCountryAreAnotherNumber(): void
    {
        self::assertSame('+41791234567', $this->type('CH')->toStorage('079 123 45 67', $this->field()));
        self::assertSame('+49791234567', $this->type('DE')->toStorage('079 123 45 67', $this->field()));
    }

    /**
     * The per-field override: this field's country wins over the installation's,
     * and the installation's still applies to every other field.
     */
    public function testAFieldMayNameItsOwnCountry(): void
    {
        $type = $this->type('CH');

        self::assertSame('+49791234567', $type->toStorage('079 123 45 67', $this->field('DE')));
        self::assertSame('+41791234567', $type->toStorage('079 123 45 67', $this->field()));
    }

    /**
     * A value that cannot be read comes back **as it was typed**, not as null.
     *
     * The load-bearing half of `toStorage()`: null would mean "no value", so a
     * mistyped number would save as an empty field and nothing would ever have
     * said no.
     */
    public function testSomethingUnreadableIsHandedBackForTheValidatorToRefuse(): void
    {
        self::assertSame('nonsense', $this->type()->toStorage('nonsense', $this->field()));
        self::assertSame('079 123 45', $this->type()->toStorage('079 123 45', $this->field()));
    }

    public function testEmptyIsNoNumberRatherThanABadOne(): void
    {
        self::assertNull($this->type()->toStorage('', $this->field()));
        self::assertNull($this->type()->toStorage(null, $this->field()));
    }

    /**
     * With no country anywhere, only a number carrying its own country code can
     * be read — which is as much as anybody can honestly conclude from digits.
     */
    public function testWithNoCountryOnlyAFullyWrittenNumberIsReadable(): void
    {
        $type = $this->type(null);

        self::assertSame('+41791234567', $type->toStorage('+41 79 123 45 67', $this->field()));
        self::assertSame('079 123 45 67', $type->toStorage('079 123 45 67', $this->field()));
    }

    /** Four refusals, because there are four different things to go and do. */
    public function testTheReasonForARefusalIsSpecific(): void
    {
        $numbers = $this->numbers('CH');

        self::assertSame(PhoneProblem::NotANumber, $numbers->read('nonsense', 'CH')->problem);
        self::assertSame(PhoneProblem::NotDiallable, $numbers->read('079 123 45', 'CH')->problem);
        self::assertSame(PhoneProblem::NoCountry, $numbers->read('079 123 45 67', null)->problem);
        self::assertSame(
            PhoneProblem::CarriesAnExtension,
            $numbers->read('+41 44 668 18 00 ext. 12', null)->problem,
        );
    }

    /**
     * The extension decision, and the measurement behind it.
     *
     * E.164 has no room for one, so formatting drops it silently: without this
     * refusal, a switchboard and the twelve people behind it would be one stored
     * value. The assertion is the *reason*, not the policy — if libphonenumber
     * ever started keeping extensions in E.164, this is what would go red and
     * tell somebody the decision is worth revisiting.
     */
    public function testAnExtensionWouldBeSilentlyLostWhichIsWhyItIsRefused(): void
    {
        $numbers = $this->numbers('CH');

        self::assertSame('+41446681800', $numbers->read('+41 44 668 18 00', null)->e164);
        self::assertNull($numbers->read('+41 44 668 18 00 ext. 12', null)->e164, 'refused rather than truncated');
    }

    /**
     * National where it is local, international where it is not — the reader's
     * country decides, not the number's.
     */
    public function testItIsShownTheWayTheReaderWritesNumbers(): void
    {
        $numbers = $this->numbers('CH');

        self::assertSame('079 123 45 67', $numbers->display('+41791234567', 'CH'));
        self::assertSame('+41 79 123 45 67', $numbers->display('+41791234567', 'DE'));
        self::assertSame('+41 79 123 45 67', $numbers->display('+41791234567', null));
    }

    /**
     * A value stored before this field type existed still shows. Nothing
     * revalidates on read: a stored number is a fact about a customer, and a
     * library update is not a reason to blank one out.
     */
    public function testSomethingUnreadableStillShowsAsItWasStored(): void
    {
        self::assertSame('call the office', $this->numbers('CH')->display('call the office', 'CH'));
    }

    /**
     * Demo data is a real number of that country's shape, and a different one
     * each time — fifty thousand copies of Google's example number would collide
     * on any field somebody marked unique.
     */
    public function testDemoDataIsPlausibleAndVaried(): void
    {
        $numbers = $this->numbers('CH');

        $first = $numbers->example('CH', 1);
        $second = $numbers->example('CH', 2);

        self::assertNotNull($first);
        self::assertNotSame($first, $second);
        self::assertSame($first, $numbers->read((string) $first, null)->e164, 'and it is one this type would accept');
        self::assertStringStartsWith('+31', (string) $numbers->example('NL'), 'a Dutch field gets a Dutch example');
    }

    /** Text in the payload, canonical already: no cast, and any index still usable. */
    public function testItComparesAsText(): void
    {
        self::assertSame("data->>'phone'", $this->type()->comparableSql("data->>'phone'"));
    }

    private function type(?string $instanceRegion = 'CH'): PhoneFieldType
    {
        return new PhoneFieldType($this->numbers($instanceRegion));
    }

    private function numbers(?string $instanceRegion): PhoneNumbers
    {
        return new PhoneNumbers(new class($instanceRegion) implements InstanceRegion {
            public function __construct(private readonly ?string $region)
            {
            }

            public function region(): ?string
            {
                return $this->region;
            }
        });
    }

    /** A real definition rather than a mock: the type reads its options. */
    private function field(?string $region = null): FieldDefinition
    {
        $field = new FieldDefinition(
            new ModuleDefinition('contact', 'Contacts', 'contact'),
            'phone',
            'Phone',
            PhoneFieldType::KEY,
        );

        if ($region !== null) {
            $field->setOptions([PhoneRegion::OPTION => $region]);
        }

        return $field;
    }
}
