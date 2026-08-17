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

namespace Xivi\Core\Form;

use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;
use Xivi\Core\Record\Candidate;
use Xivi\Core\Record\RecordCandidates;

/**
 * The choice list of a reference that is searched rather than scrolled
 * (XIV-36).
 *
 * **This class exists because of what a `<select>` promises.** ChoiceType
 * validates a submitted value against the choices it rendered — which is
 * correct, and is precisely what an autocompleting picker breaks: the options in
 * the page are one dropdown's worth, and the whole point is that somebody types
 * and picks something that was never in it. Left alone, ChoiceType answers "This
 * value is not valid" for the record the widget itself just offered.
 *
 * Symfony's answer to that is `ChoiceLoaderInterface`, whose entire reason for
 * existing is a list too large to materialise: load what has to be rendered, and
 * resolve a submitted value on demand. So this is the framework's own seam
 * rather than an escape from it — no `allow_extra_fields`, no data transformer
 * doing validation's job, and no widening of what the plain select accepts.
 *
 * **What is loaded and what is not:**
 *
 * - {@see self::loadChoiceList()} returns only the records this form has been
 *   *told* about — in practice the one it starts with, so an edit form shows
 *   what is linked today. Everything else arrives from the endpoint as somebody
 *   types, which is why there is no page of two hundred to preload and no
 *   ceiling to apologise for (XIV-35).
 * - {@see self::loadChoicesForValues()} answers "may this id be picked", once,
 *   through {@see RecordCandidates::byId()} — the same access rule and the same
 *   variant narrowing the endpoint applies. That is the load-bearing half: a
 *   value typed into the request by hand goes through it exactly as one clicked
 *   in the dropdown does, so the widget and the form cannot come to different
 *   conclusions about whose records these are.
 *
 * **It is mutable, which a loader normally is not**, and the reason is the order
 * Symfony does things in. The choice list is resolved as an option, before any
 * data reaches the form; the record being edited arrives afterwards, on
 * `PRE_SET_DATA`. {@see self::offer()} is how the form type hands it over in
 * between — see {@see RecordReferenceType::buildForm()}. Symfony's own
 * `LazyChoiceList` only calls `loadChoiceList()` when the view is built, which
 * is after that, so the list is complete by the time anybody reads it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordChoiceLoader implements ChoiceLoaderInterface
{
    /**
     * Candidates this list knows about, label => id.
     *
     * Keyed by label because that is the shape Symfony reads option text out of:
     * `ArrayChoiceList` keeps the array keys and hands them back as the original
     * keys the view labels itself from.
     *
     * @var array<string, int>
     */
    private array $known = [];

    public function __construct(
        private readonly RecordCandidates $candidates,
        private readonly string $moduleKey,
        private readonly ?string $variant,
    ) {
    }

    /**
     * Put a record in the list, if this reader may have it.
     *
     * Silently does nothing when they may not, which is the same answer as a
     * link they are not offered (§8.4, XIV-42): a form holding a record they
     * cannot see shows an empty picker rather than an error, and saving without
     * touching it is refused by the choice list below rather than by a message
     * explaining whose record it is.
     */
    public function offer(int $id): void
    {
        $candidate = $this->candidates->byId($this->moduleKey, $this->variant, $id);

        if ($candidate !== null) {
            $this->remember($candidate);
        }
    }

    public function loadChoiceList(?callable $value = null): ChoiceListInterface
    {
        return new ArrayChoiceList($this->known, $value);
    }

    /**
     * The ids among these that may actually be picked.
     *
     * Anything not returned makes ChoiceType refuse the submission, which is the
     * behaviour wanted for a deleted record, another customer's id, the wrong
     * variant and a number somebody typed into the request — all of which
     * {@see RecordCandidates::byId()} answers null for and deliberately does not
     * distinguish between.
     *
     * @param list<string|int|null> $values
     *
     * @return list<int>
     */
    public function loadChoicesForValues(array $values, ?callable $value = null): array
    {
        $choices = [];

        foreach ($values as $submitted) {
            if ($submitted === null || $submitted === '' || !is_numeric($submitted)) {
                continue;
            }

            $candidate = $this->candidates->byId($this->moduleKey, $this->variant, (int) $submitted);

            if ($candidate !== null) {
                // Remembered, so that redrawing the form after a refused save
                // still shows what was picked rather than an empty box beside
                // the message about the field that was actually wrong.
                $this->remember($candidate);
                $choices[] = $candidate->id;
            }
        }

        return $choices;
    }

    /**
     * The values these choices are submitted as.
     *
     * An id, as a string, which is what `ArrayChoiceList` does with scalar
     * choices when nothing overrides it — mirrored here rather than delegated,
     * because delegating would mean loading a list to look up a value that is
     * the choice itself.
     *
     * @param list<int|null> $choices
     *
     * @return list<string>
     */
    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        return array_map(
            static fn (mixed $choice): string => $value === null ? (string) $choice : (string) $value($choice),
            $choices,
        );
    }

    private function remember(Candidate $candidate): void
    {
        $this->known[$candidate->label] = $candidate->id;
    }
}
