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

namespace Xivi\Core\Demo;

/**
 * Which kind of word a field wants, decided by what it is called.
 *
 * A deliberate heuristic, and worth being honest about: the engine knows a field
 * is text, never that it holds a surname. Everything else in Xivi is driven by
 * the definitions and refuses to guess — but demo data reading "placeholder
 * text, placeholder text" tells you nothing about whether a list of contacts is
 * usable, and a generator is a development tool rather than part of the model.
 *
 * Being wrong costs a silly-looking demo record and nothing else. No module
 * declares anything for this, and nothing outside demo generation reads it.
 *
 * Where being wrong is worth correcting, the field says so rather than this
 * class growing a case for it: an article's `title` is a name and is not a
 * company's, which the last arm below cannot tell and the article module can
 * (XIV-24, FieldSampler). This heuristic is for everything nobody has said
 * anything about, which is most fields and always will be.
 *
 * The dispatch lives here rather than in the vocabulary because it is the same
 * question whichever words are behind it — a library that hands out names still
 * has to be *asked* for a name rather than a city, so swapping the words does
 * not remove this decision, it only makes the answers better.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SampleVocabulary
{
    public function __construct(private SampleValues $values)
    {
    }

    /**
     * Matched on substrings rather than exact keys, so `email_private`,
     * `billing_city` and `contact_first_name` all land somewhere sensible
     * without anybody enumerating them.
     */
    public function forKey(string $key): string
    {
        $key = mb_strtolower($key);

        return match (true) {
            str_contains($key, 'first_name') || str_contains($key, 'firstname') => $this->values->firstName(),
            str_contains($key, 'last_name') || str_contains($key, 'lastname'),
            str_contains($key, 'surname') => $this->values->lastName(),
            str_contains($key, 'company') || str_contains($key, 'organisation'),
            str_contains($key, 'organization') => $this->values->company(),
            str_contains($key, 'city') || str_contains($key, 'town'),
            str_contains($key, 'ort') => $this->values->city(),
            str_contains($key, 'street') || str_contains($key, 'address'),
            str_contains($key, 'strasse') => $this->values->street(),
            str_contains($key, 'country') || str_contains($key, 'land') => $this->values->country(),
            str_contains($key, 'phone') || str_contains($key, 'tel'),
            str_contains($key, 'mobile') || str_contains($key, 'fax') => $this->values->phone(),
            str_contains($key, 'label') || str_contains($key, 'kind'),
            str_contains($key, 'type') => $this->values->label(),
            // A name that is not a person's is most likely a thing's.
            str_contains($key, 'name') || str_contains($key, 'title') => $this->values->company(),
            default => $this->values->filler(),
        };
    }

    public function firstName(): string
    {
        return $this->values->firstName();
    }

    public function lastName(): string
    {
        return $this->values->lastName();
    }
}
