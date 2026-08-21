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
use Xivi\Core\Record\DistinctLabels;
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
 * - {@see self::loadChoiceList()} returns the records this form has been *told*
 *   about — in practice the one it starts with, so an edit form shows what is
 *   linked today. Everything else arrives from the endpoint as somebody types,
 *   which is why there is no page of two hundred to preload and no ceiling to
 *   apologise for (XIV-35). **A loader filling a plain select adds that
 *   select's page to it** (XIV-175), which is the one case where this class
 *   draws options rather than only resolving them; see {@see
 *   RecordReferenceType} for why a select ever has a loader.
 * - {@see self::loadChoicesForValues()} answers "may this id be picked", once,
 *   through {@see RecordCandidates::byId()} — the same access rule and the same
 *   narrowing to a set of kinds the endpoint applies (XIV-172). That is the
 *   load-bearing half: a value typed into the request by hand goes through it
 *   exactly as one clicked in the dropdown does, so the widget and the form
 *   cannot come to different conclusions about whose records these are, or about
 *   whether a voucher meant for one line may be put on the document. The one
 *   value it answers differently about is the one the form was *given*
 *   ({@see self::resolve()}, XIV-175), because keeping what a record already
 *   holds is not the same question as picking something new.
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
     * Candidates this list knows about, id => what the record is called.
     *
     * **Keyed by id here and by label at the end** (XIV-167), and the two halves
     * of that sentence are both load-bearing. `ArrayChoiceList` reads option
     * text out of the array *keys*, keeping them and handing them back as the
     * original keys the view labels itself from, so what {@see
     * self::loadChoiceList()} hands it has to be label => id, and keying this by
     * id instead would only move the problem into the flip.
     *
     * The problem is that a label is not unique and an id is. Every record here
     * arrives through {@see RecordCandidates::byId()}, one at a time, and a
     * single record has nothing to be disambiguated against, so `byId()` answers
     * with the bare title. Collected label => id, two records sharing a title
     * wrote the same key and the second overwrote the first: an edit form on a
     * field naming both of them rendered one option, showed one selection, and
     * dropped the other link the moment anybody saved. Which of the two
     * survived was whichever was offered last.
     *
     * So the titles are collected under the one key that cannot collide, and the
     * labels are made distinct once, at the end, when the whole set is in hand,
     * by {@see DistinctLabels}, which is the same rule the select path has
     * always applied through {@see RecordCandidates::find()}. That timing is
     * what makes reusing the rule possible at all: it needs to see the records
     * beside each other, and this class is handed them one by one.
     *
     * @var array<int, string>
     */
    private array $titles = [];

    /**
     * The ids this form was handed as its record's own (XIV-175).
     *
     * Small, and it is the record's links rather than anything a request said:
     * {@see RecordReferenceType::buildForm()} fills it on `PRE_SET_DATA` from
     * the data the form is built with. What it is for is written on
     * {@see self::offer()} and on {@see RecordCandidates::held()}.
     *
     * @var array<int, true>
     */
    private array $held = [];

    /**
     * @param list<string>    $variants which kinds this picker may hold; empty for
     *                                  all of them. The same set the endpoint
     *                                  behind the widget was given, or the widget
     *                                  would suggest records this then refuses
     *                                  (XIV-172)
     * @param ?CandidateLists $listing  the page a plain select renders, when this
     *                                  loader is filling one (XIV-175). Null for
     *                                  the search box, which has no page: its
     *                                  options arrive from the endpoint and
     *                                  nothing but the record's own links belongs
     *                                  in the page. See
     *                                  {@see RecordReferenceType} for why a
     *                                  select ever has a loader at all
     */
    public function __construct(
        private readonly RecordCandidates $candidates,
        private readonly string $moduleKey,
        private readonly array $variants,
        private readonly ?CandidateLists $listing = null,
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
     *
     * **Through {@see RecordCandidates::held()} rather than `byId()`**
     * (XIV-175), which is the whole of how a document keeps a voucher that
     * expired after it was agreed. This method is called with what the record
     * *has*, never with what a request said, so the two questions genuinely are
     * different: `byId()` answers "may this be picked", and a value already
     * stored has been picked. Narrowing it here would mean an order opened after
     * the promotion ended lost its discount by being looked at, which is the
     * opposite of what the engine decided (§5.9, XIV-110). Since [XIV-176] the
     * field's own kinds are held back for the same reason, which is why this
     * hands over no variants: a narrowing that reached the tenant after the
     * document was agreed would otherwise take the voucher off it.
     */
    public function offer(int $id): void
    {
        $candidate = $this->candidates->held($this->moduleKey, $id);

        if ($candidate !== null) {
            $this->held[$candidate->id] = true;
            $this->remember($candidate);
        }
    }

    /**
     * Everything offered so far, spelled so a reader can tell it apart.
     *
     * The labelling happens here rather than in {@see self::remember()} because
     * it is a question about a *set*: whether a title needs its id beside it
     * depends on what else ended up in the list, and nothing knows that until
     * the list is finished. Symfony's own ordering makes the timing work:
     * `LazyChoiceList` only asks for this when the view is built, which is after
     * `PRE_SET_DATA` has offered the record's links and after a submission has
     * resolved whatever was picked.
     */
    public function loadChoiceList(?callable $value = null): ChoiceListInterface
    {
        $known = [];

        // The select's page first, so the options stay in the order somebody is
        // scanning, and whatever the record itself holds after it. Records in
        // both are one entry: the page's title is written first and the offered
        // one writes the same key again.
        foreach (DistinctLabels::among($this->listed() + $this->titles) as $id => $label) {
            $known[$label] = $id;
        }

        return new ArrayChoiceList($known, $value);
    }

    /**
     * The page a plain select renders, when this loader is filling one
     * (XIV-175).
     *
     * Read through {@see CandidateLists}, which is the memo the form type
     * already asked for its count, so drawing the options costs no query the
     * form was not making and five hundred collection rows still read one page
     * (XIV-87). Empty for a search box, which has no page at all.
     *
     * The labels come back already distinguished, since {@see
     * RecordCandidates::find()} applies {@see DistinctLabels} to what it hands
     * out. Running the rule again over the union is not a second spelling of it:
     * two distinct labels stay as they are, and the one case that matters is a
     * held record whose title collides with a listed one, which is exactly the
     * pair that has to be told apart.
     *
     * @return array<int, string>
     */
    private function listed(): array
    {
        $titles = [];

        foreach ($this->listing?->for($this->moduleKey, $this->variants)['choices'] ?? [] as $label => $id) {
            $titles[$id] = $label;
        }

        return $titles;
    }

    /**
     * The ids among these that may actually be picked.
     *
     * Anything not returned makes ChoiceType refuse the submission, which is the
     * behaviour wanted for a deleted record, another customer's id, a kind this
     * picker does not offer and a number somebody typed into the request, all of
     * which
     * {@see RecordCandidates::byId()} answers null for and deliberately does not
     * distinguish between.
     *
     * **Under the keys they were asked for, and that is the interface's rule
     * rather than a nicety** (XIV-167). `ChoiceLoaderInterface` says the choices
     * come back "with the same keys and in the same order as the corresponding
     * values in the given array", and `ArrayChoiceList::getChoicesForValues()`
     * honours it by assigning at `$i`. This appended instead, which re-indexes
     * from zero, and ChoiceType is a consumer that reads the keys back:
     *
     *     foreach ($choiceList->getChoicesForValues($data) as $key => $choice) {
     *         $knownValues[] = $data[$key];
     *     }
     *
     * So every key after a refused value was off by one, and it went wrong in
     * two directions. A submission whose keys do not start at zero, which a
     * Live Component model produces the moment a list is written sparsely because
     * its values travel as JSON, shifted straight off the end of `$data`, which
     * is the "Undefined array key 0" this was reported as. And refusing one id
     * out of three made `$knownValues` pick up the **refused** value while
     * dropping a real one, so what ChoiceType went on to submit was a set
     * assembled partly out of an id this method had just said no to.
     *
     * **What that second one did not do is quietly save the wrong set**, and the
     * reason is worth being uncomfortable about rather than reassured by:
     * `ChoicesToValuesTransformer` sits behind this and refuses when it gets back
     * fewer choices than it passed values, so the refused id was caught one layer
     * down and the whole save came back as "The selected choice is invalid",
     * about a set somebody had picked entirely out of the widget's own
     * suggestions. A loader that answers wrongly and is saved by the strictness
     * of its caller is still answering wrongly, and nothing in
     * `ChoiceLoaderInterface` promises the next caller checks.
     *
     * Preserving the key keeps a refusal a refusal: the value disappears from
     * the answer at the key it was asked about, the ones beside it are unmoved,
     * and ChoiceType finds the refused one among the unknown values instead of
     * among the submitted ones.
     *
     * @param array<array-key, string|int|null> $values
     *
     * @return array<array-key, int>
     */
    public function loadChoicesForValues(array $values, ?callable $value = null): array
    {
        $choices = [];

        foreach ($values as $key => $submitted) {
            if ($submitted === null || $submitted === '' || !is_numeric($submitted)) {
                continue;
            }

            $candidate = $this->resolve((int) $submitted);

            if ($candidate !== null) {
                // Remembered, so that redrawing the form after a refused save
                // still shows what was picked rather than an empty box beside
                // the message about the field that was actually wrong.
                $this->remember($candidate);
                $choices[$key] = $candidate->id;
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
     * **The same keys back, like its counterpart** (XIV-167), which this has
     * always done without saying so: `array_map()` over exactly one array keeps
     * that array's keys. It is written down because the two halves have to agree
     * (a loader that preserved keys one way and re-indexed the other would put
     * a form's view data and its submitted data on different footings), and
     * because `array_map()` stops preserving them the moment a second array is
     * passed, which makes this a property of the call rather than of the
     * function.
     *
     * @param array<array-key, int|null> $choices
     *
     * @return array<array-key, string>
     */
    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        return array_map(
            static fn (mixed $choice): string => $value === null ? (string) $choice : (string) $value($choice),
            $choices,
        );
    }

    /**
     * One submitted id, as either a choice or a value being kept (XIV-175).
     *
     * The difference is one question: was this form *given* this id as its
     * record's own? An id it was given goes through
     * {@see RecordCandidates::held()}, which applies everything except the
     * module's own narrowing and the field's kinds, so re-saving an order that
     * carries a voucher which has since expired, or one whose family the
     * picker stopped offering ([XIV-176]), stores the same voucher it already
     * stored. Everything else goes through {@see RecordCandidates::byId()} and
     * meets both narrowings in full.
     *
     * **A crafted id cannot reach the first branch**, because
     * {@see self::offer()} is what fills `$held` and the form type calls it on
     * `PRE_SET_DATA` with the record's stored links. The most a submission can
     * do by naming a held id is leave the record holding what it already held.
     */
    private function resolve(int $id): ?Candidate
    {
        return isset($this->held[$id])
            ? $this->candidates->held($this->moduleKey, $id)
            : $this->candidates->byId($this->moduleKey, $this->variants, $id);
    }

    /**
     * Keeping a candidate, by the one key two of them cannot share.
     *
     * Offering the same record twice is ordinary rather than a mistake: an edit
     * form offers its stored links and then resolves the submitted ones, which
     * for a save that changed nothing is the same ids again. By id that is
     * simply the same entry written twice.
     */
    private function remember(Candidate $candidate): void
    {
        $this->titles[$candidate->id] = $candidate->label;
    }
}
