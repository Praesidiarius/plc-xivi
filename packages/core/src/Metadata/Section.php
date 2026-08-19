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

namespace Xivi\Core\Metadata;

use Symfony\Component\String\Slugger\AsciiSlugger;
use Xivi\Core\Entity\ModuleDefinition;

/**
 * A heading on a form, and the fields drawn under it (XIV-119).
 *
 * **This is presentation and nothing else.** A field in a section is the same
 * field, in the same record, under the same key of the same JSON payload; only
 * the form and the record page draw it differently. Nothing here reaches
 * storage, validation, filtering, export or a document marker — §5.7 addresses
 * fields by key and has never heard of this — and the moment any of that stops
 * being true, a section has quietly become a second way of grouping records,
 * which §5.1's collections already are.
 *
 * That is why a section is a **value** rather than an entity: it has no row, no
 * id and no table, so there is nothing for a query to join to and nothing for a
 * foreign key to point at. It is part of how one customer's copy of one module
 * is drawn, which is exactly the sentence {@see ModuleDefinition} is the
 * definition of — the same argument its `followUpsEnabled` flag makes for living
 * on that row instead of in a table of its own.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Section
{
    /**
     * The language the key is derived in, pinned rather than taken from the
     * request.
     *
     * [XIV-100]'s argument about the self-service slug, applied one more time:
     * what comes out of this is permanent and the language somebody happened to
     * have the page open in is not, so "Übersicht" has to become the same key
     * whoever types it.
     */
    private const string TRANSLITERATION_LOCALE = 'de';

    public function __construct(
        /** Never shown, never renamed, and what a field carries to say it is in here. */
        public string $key,
        /** The customer's own word, freely renameable — see the class docblock. */
        public string $label,
        /** Where the heading sits among the others (§5.4). */
        public int $position,
    ) {
    }

    /**
     * The key a label gets, once, and never again.
     *
     * The same split a field's key and label already make, and the same one
     * XIV-144 made for a choice field's options: what is stored is derived from
     * the first label somebody gives and is then permanent, so renaming a
     * section afterwards changes what the page says and moves no field. Asking
     * for the key as well would be asking somebody who wants a heading called
     * "Billing" to understand a distinction that only matters when it is too
     * late to change it.
     *
     * Deliberately its own implementation rather than a call into
     * {@see \Xivi\Core\Field\Type\ChoiceFieldType::valueFor()}, which does the
     * same arithmetic: the shared thing is §5.4's *rule*, not the function, and
     * the one place the two differ is the word each falls back to — an option
     * that slugs to nothing is an `option`, and a heading is a `section`.
     * Metadata reaching into a field type for a slug would also be the wrong
     * direction through the engine.
     *
     * @param array<string, mixed> $taken what the shape already has, so a key derived now
     *                                    cannot collide with one fields already carry
     */
    public static function keyFor(string $label, array $taken = []): string
    {
        $key = new AsciiSlugger(self::TRANSLITERATION_LOCALE)->slug($label, '_')->lower()->toString();
        // The slugger keeps a few characters that are legal in a URL and not in
        // an identifier — a dot, a plus — so the pattern the rest of the engine
        // uses is applied rather than assumed.
        $key = trim((string) preg_replace('/[^a-z0-9_]+/', '_', $key), '_');

        if ($key === '' || ctype_digit($key[0])) {
            // A key has to start with a letter, and "2026" is a perfectly
            // ordinary heading on a form.
            $key = $key === '' ? 'section' : 'section_' . $key;
        }

        if (!\array_key_exists($key, $taken)) {
            return $key;
        }

        $suffix = 2;

        while (\array_key_exists($key . '_' . $suffix, $taken)) {
            ++$suffix;
        }

        return $key . '_' . $suffix;
    }

    /**
     * One section as it is stored, which is a value in a JSON column rather than
     * a row.
     *
     * @return array{label: string, position: int}
     */
    public function stored(): array
    {
        return ['label' => $this->label, 'position' => $this->position];
    }

    /**
     * And back again, defensively.
     *
     * A definition row is data a migration never rewrote and a customer's own
     * export may have been round-tripped through, so a missing label reads as
     * the key rather than as a blank heading, and a missing position as the top.
     * Neither is a state anything here can produce; both are states a file can.
     *
     * @param array<string, mixed> $stored
     */
    public static function from(string $key, array $stored): self
    {
        $label = $stored['label'] ?? null;
        $position = $stored['position'] ?? null;

        return new self(
            $key,
            \is_string($label) && trim($label) !== '' ? $label : $key,
            \is_int($position) ? $position : 0,
        );
    }

    public function renamedTo(string $label): self
    {
        return new self($this->key, $label, $this->position);
    }

    public function movedTo(int $position): self
    {
        return new self($this->key, $this->label, $position);
    }
}
