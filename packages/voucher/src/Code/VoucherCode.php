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

namespace Xivi\Voucher\Code;

/**
 * What a voucher is called, and the two rules that make the name work (XIV-103).
 *
 * A voucher code is the one value in this system that is designed to leave the
 * building. It is printed on a flyer, said down a telephone, pasted into an
 * email and typed back in by somebody who has never seen this application. Every
 * decision in this class follows from that one sentence.
 *
 * ### Case: folded to upper, in exactly one place
 *
 * `give-10` and `GIVE-10` are the same voucher to a human being, so they have to
 * be the same voucher here. There are two ways to arrange that and only one of
 * them survives contact with the database.
 *
 * The tempting one is a *case-insensitive comparison*: `LOWER(code) = LOWER(?)`
 * wherever a code is looked up. It loses, and it loses for a reason that is
 * structural rather than stylistic. Since [XIV-109] a `unique` field is enforced
 * by a **unique expression index over `data ->> 'code'`**, which is
 * case-sensitive, because that is what Postgres does with text. A
 * case-insensitive rule in PHP and a case-sensitive index in the database do not
 * merely differ in style — they *disagree about what a duplicate is*, and the
 * database is the one that is actually true. `give-10` would be accepted beside
 * `GIVE-10` by the index and then found by either spelling by the lookup, which
 * is two vouchers answering to one name.
 *
 * So the fold happens **on the way in**, before validation and before storage,
 * and nothing downstream ever has to know about case again. The single place is
 * {@see VoucherCodeFieldType::toStorage()}, which is not a convenient hook but
 * the engine's own normalisation seam: {@see \Xivi\Core\Validation\RecordValidator}
 * runs values through it before validating ("values are validated in the shape
 * they will be stored in"), {@see \Xivi\Core\Record\RecordRepository} runs them
 * through it before writing, and {@see \Xivi\Core\Query\QueryCompiler} runs them
 * through it before comparing. One method covers the form, the import, the
 * validator, the unique index and every future lookup by code, including the one
 * [XIV-104] will make when somebody redeems one.
 *
 * `mb_strtoupper` rather than `strtoupper`, though on PHP 8.2+ the latter is
 * locale-insensitive and would do for ASCII. The difference shows on a code
 * somebody pastes with an accent or in Cyrillic: `strtoupper` would leave it
 * alone and `mb_strtoupper` folds it, and while {@see PATTERN} refuses both, a
 * value that has been folded consistently is a value the refusal can quote back
 * without looking like it changed under them.
 *
 * ### Two alphabets, because two different things choose the characters
 *
 * **What a customer may type** is deliberately wide: `A-Z`, `0-9` and the hyphen.
 * `GIVE-10` is the entire point of letting them choose — a code people can say
 * out loud beats a code that is merely unguessable — and narrowing that set would
 * refuse the very example the feature exists for. `1` and `0` are in it because
 * `GIVE-10` contains both.
 *
 * **What the generator may pick** is narrow, and for a reason that does not
 * apply to the customer's own choice: nobody chose those characters, so nothing
 * is lost by leaving out the ones that get read wrong. {@see ALPHABET} drops
 * `0`/`O` and `1`/`I`/`L`, which is the pair and the trio that a person reading a
 * code off a screen and a person typing it into a phone disagree about. It also
 * drops `U`, which is Crockford's addition to the same list and is there for a
 * different reason: eight random letters occasionally spell something a customer
 * has to apologise for, and removing one vowel makes that markedly less likely.
 * It is a mitigation and not a guarantee, and it is cheap enough to be worth
 * having anyway.
 *
 * The line is drawn at Crockford's published set rather than at a longer one
 * invented here. `S`/`5`, `2`/`Z` and `8`/`B` are also confusable in some fonts
 * and are kept, because every character removed costs entropy and the argument
 * for removing them is weaker than the argument that a widely used answer is
 * better than a bespoke one.
 *
 * ### Shape, and why it is random rather than sequential
 *
 * Four characters, a hyphen, four characters — `HK4T-9PQM`. The group is there
 * to be read out loud in two breaths, which is how people actually dictate
 * codes, and it makes a transposition visible rather than merely wrong.
 *
 * **Not a sequence.** Document numbers are sequential on purpose (§5.10) because
 * an order is a record of the business and gaps in it are questions. A voucher
 * code is the opposite: a sequential one lets anybody holding `AB-0004` guess
 * `AB-0005`, which is somebody else's money. That is also why the characters come
 * from `random_int()` rather than `mt_rand()` — Mersenne Twister's state is
 * recoverable from its output, and a value worth money should not be drawn from a
 * generator whose next value can be worked out from the last few.
 *
 * Thirty characters in eight positions is about 6.6 * 10^11 codes. A tenant
 * holding ten thousand generated vouchers occupies roughly one in sixty-six
 * million of that space, which is the number that makes the collision retry in
 * {@see AssignsVoucherCodes} a formality rather than a mechanism.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class VoucherCode
{
    /**
     * The characters a *generated* code is drawn from.
     *
     * `0-9A-Z` less `0`, `1`, `I`, `L`, `O` and `U`. See the class docblock for
     * which of those are here because of reading and which because of reading
     * something unintended.
     */
    public const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Characters per group, and how many groups. `HK4T-9PQM`. */
    public const int GROUP = 4;
    public const int GROUPS = 2;

    /**
     * What a code may look like once it has been folded.
     *
     * Groups of `A-Z0-9` joined by single hyphens, which refuses the three
     * mistakes that are only ever mistakes: a leading hyphen, a trailing one, and
     * two in a row. None of those survives being read aloud, and accepting them
     * would mean `GIVE--10` and `GIVE-10` are different vouchers that sound
     * identical.
     *
     * A space is refused rather than repaired. "GIVE 10" might mean `GIVE-10` or
     * `GIVE10`, and guessing which would silently store a code the customer did
     * not type — the one failure mode that is worse than an error message,
     * because the flyer has already gone to print.
     */
    public const string PATTERN = '/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/';

    /**
     * Short enough to be typed and long enough to be memorable.
     *
     * The lower bound is three because a one- or two-character code cannot be
     * dictated without ambiguity and cannot be searched for; the upper bound is
     * thirty-two because a code is a name rather than a sentence, and because it
     * keeps a code comfortably inside a printed line.
     */
    public const int MIN_LENGTH = 3;
    public const int MAX_LENGTH = 32;

    /**
     * The one fold, called by the one caller that matters.
     *
     * Null in, null out, and whitespace-only in, null out: the engine's
     * convention is that an empty string and "not filled in" are the same thing
     * (see {@see \Xivi\Core\Field\Type\TextFieldType::toStorage()}), and keeping
     * them distinct here would create a second way for a voucher to have no code.
     *
     * Trimmed because a code pasted out of an email arrives with a space on the
     * end far more often than anybody expects, and refusing that would be a
     * refusal about the clipboard rather than about the voucher.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $folded = mb_strtoupper(trim($value));

        return $folded === '' ? null : $folded;
    }

    /**
     * A code nobody chose, in the alphabet chosen for them.
     *
     * `random_int()` throws when the platform has no source of randomness, which
     * is a condition this code has no sensible answer to and deliberately does
     * not catch: a voucher code drawn from a weak generator is worse than no
     * voucher at all, and the exception is the honest outcome.
     *
     * Already normalised by construction — the alphabet is upper case — so it can
     * go straight into a record without a second fold. That is a property worth
     * having rather than an accident: a generator that produced values its own
     * normaliser would change would be a generator nobody could reason about.
     */
    public static function generate(): string
    {
        $groups = [];

        for ($group = 0; $group < self::GROUPS; ++$group) {
            $characters = '';

            for ($position = 0; $position < self::GROUP; ++$position) {
                $characters .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }

            $groups[] = $characters;
        }

        return implode('-', $groups);
    }

    /**
     * Whether a folded code is one this module will accept.
     *
     * Here rather than only in the validator's constraints so that the generator
     * and the test suite can ask the same question the form asks — a generated
     * code that its own field would refuse is the kind of defect that only shows
     * up once somebody presses the button.
     */
    public static function isWellFormed(string $folded): bool
    {
        $length = mb_strlen($folded);

        return $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $folded) === 1;
    }
}
