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

namespace Xivi\Core\Record;

use Xivi\Core\Entity\ModuleDefinition;

/**
 * How many rows a record form came back with, counted without building a form
 * (XIV-90).
 *
 * The mirror image of {@see RecordFormData}, and it lives here for the same
 * reason: what a form *starts* with is a fact about a shape and a record, and
 * what a form *comes back with* is a fact about a shape and an array. Neither
 * needs a request, a controller or a `FormView`, and core already owns the
 * shape both of them are about.
 *
 * **Why counting has to happen before the form exists.** XIV-68 capped a
 * collection at {@see CollectionLimit::MAX_ROWS} rows and put the check in
 * {@see RecordWriter::save()}, which is the one door every write path goes
 * through. On the form path, though, the values arrive *through* the form: a
 * submission of four hundred and one rows had to be built as four hundred and
 * one row forms before anything could count them, and the Live Component builds
 * the whole thing more than once per action — the form itself, plus the throwaway
 * one that transforms the view values back into model values. At about 0.35 MB a
 * row (§5.1) that is roughly twice 140 MB against the 256M a request is allowed,
 * so a hand-crafted over-long POST could exhaust memory *before the refusal
 * rendered* — turning a readable limit into exactly the 500 the limit exists to
 * prevent.
 *
 * So the count is taken from the submitted values while they are still the
 * plain, cheap array the browser sent. **The rows are not the weight; what the
 * rows make the page build is** — §5.1 established that measuring, and it is
 * what makes this worth doing at all: counting four hundred and one arrays of
 * six strings costs nothing.
 *
 * **This is a second caller for `CollectionLimit`, not a second rule.** The
 * number, the sentence and its placeholders stay in one place, and the writer's
 * own guard is untouched — it is what keeps the limit true for the importer, the
 * demo generator and whatever calls the writer next. This is a cheap check in
 * front of an expensive path, never a replacement for the real one.
 *
 * **A payload that cannot be counted is a different answer from one that is too
 * long.** Nothing a browser sends can put a string where a collection's rows
 * belong, so what arrives that way was written by hand and there is no honest
 * number to name in a refusal. Saying "this holds at most 400 rows and this one
 * has 0" about it would be a made-up count, so the two are kept apart:
 * {@see self::$readable} says whether there was anything to count, and only then
 * do {@see self::$counts} mean anything.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SubmittedRows
{
    /**
     * The key of the sentence for a submission that could not be counted.
     *
     * It deliberately says nothing about a limit or a number. A reader who meets
     * this has not typed too much — something between them and here has sent
     * values in a shape this form does not have — and the only useful thing to
     * tell them is to start again from the page.
     */
    public const string UNREADABLE = 'record.submission_unreadable';

    /**
     * @param array<string, int> $counts   how many rows each collection was sent,
     *                                     keyed by collection; a collection the
     *                                     submission does not mention is absent
     *                                     rather than zero, because "not sent" and
     *                                     "sent empty" mean different things to the
     *                                     writer (XIV-16)
     * @param bool               $readable whether the submission was shaped like
     *                                     a submission at all
     */
    private function __construct(
        public array $counts,
        public bool $readable,
    ) {
    }

    /**
     * What the browser sent, counted.
     *
     * **Both post shapes arrive here already reconciled**, which is the reason
     * this takes an array rather than a request. A Live Component posts one
     * multipart field holding JSON, and the rows can be in it two ways: an action
     * that replaces the whole model sends them nested —
     * `updated: {"module_record": {"collections": {"lines": [...]}}}` — while an
     * ordinary form post sends one entry per control, keyed by the path the
     * control's `name` attribute makes:
     * `updated: {"module_record.collections.lines.0.fields.text": "…"}`. On top of
     * either sits whatever the signed `props` already held, which is where the
     * rows of a record being edited come from. The library merges all three onto
     * the component's raw values before any form is built, so counting there
     * handles every shape without a second parser that could disagree with the
     * first — and a second parser is exactly the thing that would eventually
     * disagree.
     *
     * **Derived collections are skipped**, for the reason the writer's own guard
     * gives: a derived collection is the engine's arithmetic restated (§5.9), it
     * is not on the form, and a count nobody typed is not a count anybody can act
     * on.
     *
     * @param array<string, mixed> $submitted the form's raw values — `fields` and
     *                                        `collections`, as `RecordFormData`
     *                                        shapes them
     */
    public static function in(ModuleDefinition $definition, array $submitted): self
    {
        $collections = $submitted['collections'] ?? [];

        // Not an absence — an absence is a form with no collections on it, and
        // `?? []` above has already turned that into an empty count. This is a
        // value standing where the map of collections belongs.
        if (!\is_array($collections)) {
            return new self([], false);
        }

        $counts = [];

        foreach ($definition->getCollections() as $collection) {
            if ($collection->isDerived()) {
                continue;
            }

            $key = $collection->getKey();

            if (!\array_key_exists($key, $collections)) {
                continue;
            }

            if (!\is_array($collections[$key])) {
                return new self([], false);
            }

            // `count()` rather than a walk over the rows: the list may be keyed
            // by index or, once a row has been removed and the gaps not closed,
            // by whatever indices survived. Both are the same number of rows and
            // both cost the same to draw.
            $counts[$key] = \count($collections[$key]);
        }

        return new self($counts, true);
    }

    /**
     * The first collection past the cap, by key, or null if every one of them is
     * within it.
     *
     * The first rather than all of them, because the answer is the same either
     * way — nothing is saved — and one sentence naming one list is what somebody
     * can act on. The writer stops at the first over-long collection too.
     */
    public function overTheCap(): ?string
    {
        foreach ($this->counts as $key => $rows) {
            if (!CollectionLimit::allows($rows)) {
                return $key;
            }
        }

        return null;
    }
}
