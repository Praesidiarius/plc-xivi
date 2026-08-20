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

namespace Xivi\Core\Field;

/**
 * What a record holds when it holds a file (XIV-115).
 *
 * The bytes live on a filesystem and this is the whole of what the tenant's
 * database knows about them: a token naming the file inside that tenant's own
 * directory, how big it is, what it claims to be, and what the person who
 * uploaded it called it.
 *
 * ## One string, and the argument is {@see Type\PeriodFieldType}'s
 *
 * `f3c1…d0:10485760:application/pdf:Contract 2026.pdf`.
 *
 * A JSON object with four keys is the obvious spelling and is rejected for the
 * reason §5.27 already rejected it for a period: a stored value that stops being
 * a scalar is a change to the spreadsheet export (which writes cells), to the
 * history diff (which compares stored values with `===`), to the importer (which
 * reads one column per field), to `IS NULL` filtering, and to `data ->> 'key'`
 * itself. As one string none of those learn anything, and §5.6's round trip
 * (export, re-import, nothing changes) holds without the importer being taught
 * what a file is.
 *
 * **The alphabet is what makes the colon safe.** The first three parts cannot
 * contain one: the token is hex, the size is digits, and a media type's own
 * grammar (RFC 9110) is letters, digits and a handful of punctuation that
 * excludes it. The name is last and is therefore free to contain anything at
 * all, colons included, because {@see self::parse()} splits four ways and stops.
 * So there is no escape and there is nothing for an escape to get wrong.
 *
 * ## What the token is, and what it is not
 *
 * 32 hex characters from `random_bytes()`, and **not a path**. Where those bytes
 * sit is derived from the resolved tenant by the one class allowed to touch a
 * filesystem, so a value read out of a record can name a file inside that
 * tenant's directory and cannot name anything else, whatever it says (§5.30).
 * That is why the name the customer typed is carried *beside* the token rather
 * than used as one: `../../etc/passwd` is a perfectly good thing to call a
 * document and a terrible thing to build a path out of.
 *
 * It is also not a credential. Guessing one buys nothing, because the download
 * route checks the record's own permissions before it reads a byte (§8.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class StoredFile
{
    /** What separates the four parts. See the class docblock for why it is safe. */
    public const string SEPARATOR = ':';

    /** How long a token is, in hex characters. 16 bytes of randomness. */
    public const int TOKEN_LENGTH = 32;

    /** The one thing a file is called when nothing else could be read from it. */
    public const string FALLBACK_NAME = 'file';

    /**
     * What a browser is told a file is when nobody could work out what it is.
     *
     * Deliberately the vaguest type there is: an unrecognised file is offered as
     * bytes to save rather than as something to open, which is the safe half of
     * the guess.
     */
    public const string FALLBACK_TYPE = 'application/octet-stream';

    public function __construct(
        public string $token,
        public int $size,
        public string $contentType,
        public string $name,
    ) {
    }

    /**
     * A stored value, as a file, or null when it is not one.
     *
     * Null rather than an exception, and every caller here is why: a list page
     * drawing a column, a document filling a marker and the download route all
     * meet whatever is in the record, including a value under a key this field
     * inherited from a definition that has since been removed (§5.4). None of
     * them is in a position to do anything about it, so each draws nothing. The
     * one place that *does* complain is validation, through
     * {@see \Xivi\Core\Validation\ValidStoredFile}, which is where a nonsense
     * cell in an imported spreadsheet gets named.
     */
    public static function parse(mixed $stored): ?self
    {
        if (!\is_string($stored) || $stored === '') {
            return null;
        }

        $parts = explode(self::SEPARATOR, $stored, 4);

        if (\count($parts) !== 4) {
            return null;
        }

        [$token, $size, $type, $name] = $parts;

        if (!self::isToken($token) || preg_match('/^[0-9]+$/', $size) !== 1 || !self::isMediaType($type)) {
            return null;
        }

        $name = trim($name);

        return new self(
            $token,
            (int) $size,
            $type,
            $name === '' ? self::FALLBACK_NAME : $name,
        );
    }

    /** A token this application could have written, as opposed to one somebody typed. */
    public static function isToken(string $token): bool
    {
        return preg_match(sprintf('/^[0-9a-f]{%d}$/', self::TOKEN_LENGTH), $token) === 1;
    }

    /**
     * The value as the record stores it.
     *
     * The name is stripped of the two characters that would make the string
     * unreadable rather than merely ugly: a newline, which would break a
     * spreadsheet cell in two, and a control character, which nothing renders.
     * The colon is deliberately left alone; see the class docblock.
     */
    public function stored(): string
    {
        $name = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $this->name) ?? self::FALLBACK_NAME;
        $name = trim($name);

        return implode(self::SEPARATOR, [
            $this->token,
            (string) $this->size,
            $this->contentType,
            $name === '' ? self::FALLBACK_NAME : $name,
        ]);
    }

    /**
     * A media type, by its own grammar rather than by a list.
     *
     * A list of the types this application expects would be a second decision
     * about what may be uploaded, kept somewhere nobody looks; what a file *is*
     * is decided on the way in, once, and this only has to be sure the recorded
     * answer cannot corrupt the value it sits in.
     */
    private static function isMediaType(string $type): bool
    {
        // Tilde-delimited, because the alphabet a media type is allowed to use
        // contains a `#` and that is the delimiter every other pattern here
        // reaches for. A `#` inside the pattern would end it early, which reads
        // as a working expression and matches nothing it should.
        return preg_match('~^[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+$~', $type) === 1;
    }
}
