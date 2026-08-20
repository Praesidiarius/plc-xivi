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

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Record\RecordCandidates;
use Xivi\Core\Record\RecordSearchUrl;

/**
 * Picking the record a reference points at (§7.6).
 *
 * A form type rather than a plain choice list on the field type, because it has
 * to read the database to know what the choices are — and a field type is
 * handed a definition, not a connection.
 *
 * The options come from the target module's own records, named by its title
 * fields (§5.4), narrowed to a variant when the reference asks for one, and
 * scoped to what this reader may see (XIV-13), so a person's employer offers
 * companies and not every contact in the system.
 *
 * **Reading the candidates is {@see CandidateLists}' job** (XIV-87), and the
 * split is about lifetime rather than tidiness: this type is asked for a list
 * once per form, a collection row is a form, and five hundred order lines were
 * therefore five hundred identical reads. What is left here is the shape of the
 * control — the cap, the sentence under it, the placeholder — which is genuinely
 * per field.
 *
 * **Two shapes of control now, and one meaning** (XIV-36). Whether somebody
 * scrolls a select or types into a search box is an option on the field, not a
 * field type of its own, and it changes nothing about what is stored, what
 * validates, how the value filters or how it exports. What it changes is where
 * the candidates come from:
 *
 * - **The select** renders {@see CandidateLists}' page, capped at
 *   {@see self::MAX_CHOICES}, and says so when it truncates (XIV-35). A plain
 *   select, with a ceiling: beyond a few hundred candidates a dropdown has
 *   stopped being a way to find anything, and one that silently showed the first
 *   two hundred of nine thousand would be worse than one that says so. This is
 *   what `never` keeps forever, and the ceiling and its apology go together.
 * - **The search box** renders nothing but whatever is already linked and asks
 *   {@see RecordSearchUrl}'s endpoint for the rest, a page at a time, as
 *   somebody types or scrolls. There is no ceiling to apologise for, which is
 *   why there is no notice on it — the widget reaches every record the reader
 *   may see, and says "no more results" when it has.
 *
 * So under `auto` a truncated picker cannot happen: past twenty candidates the
 * ceiling is replaced rather than raised. **And it is where the pickers were
 * heaviest that this weighs least**: XIV-87 removed the queries behind a long
 * order's five hundred pickers and barely moved its memory, because each row
 * still drew two hundred `<option>` elements. A search box draws none, and a
 * catalogue big enough to make a form that heavy is a catalogue `auto` turns
 * into a search box.
 *
 * **And one picker for both arities** (XIV-113). A field that may name several
 * records passes `multiple`, and everything above this line is what it gets:
 * the same scoped candidates, the same cap with the same apology, the same
 * search box past the same count. Nothing here is per arity because nothing here
 * depends on it, which is also the answer to whether `symfony/ux-autocomplete`
 * does multi-select: its controller reads `select.multiple` and configures Tom
 * Select from it, so the widget is the package's rather than something this had
 * to grow.
 *
 * @extends AbstractType<int|list<int>|null>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordReferenceType extends AbstractType
{
    /** Above this, a dropdown has stopped being a way to find anything. */
    public const int MAX_CHOICES = 200;

    public function __construct(
        private readonly CandidateLists $lists,
        private readonly RecordCandidates $candidates,
        private readonly RecordSearchUrl $searchUrl,
    ) {
    }

    /**
     * Hands the record being edited to the choice list, when there is one.
     *
     * **Only under autocomplete**, because only then is the list otherwise
     * empty. A select already rendered every candidate it will accept, and
     * telling it about one of them again would be a second code path doing
     * nothing.
     *
     * On `PRE_SET_DATA` rather than from `$builder->getData()`, because the two
     * are not the same moment: a form built empty and filled afterwards — which
     * is what a Live Component re-render does — has no data at build time and
     * would render a picker that had forgotten what it was pointing at.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $loader = $options['choice_loader'];

        if (!$loader instanceof RecordChoiceLoader) {
            return;
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($loader): void {
            $data = $event->getData();

            // One id or several (XIV-113). A field holding a list arrives here as
            // an array and every one of its links has to be offered, or an edit
            // form would show the first tag and quietly drop the other three the
            // moment somebody saved. The loop is the whole difference: what is
            // offered, and what a submitted value is checked against, is the same
            // record read the same way whichever arity asked.
            foreach (\is_array($data) ? $data : [$data] as $id) {
                if (is_numeric($id)) {
                    $loader->offer((int) $id);
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('target_module')
            ->setAllowedTypes('target_module', 'string')
            ->setDefault('target_variant', null)
            ->setAllowedTypes('target_variant', ['null', 'string'])
            // What the field asked for, resolved by the field type and passed
            // down rather than read again here: the definition is the field
            // type's to interpret (§5), and this form type is handed the answer
            // the way it is handed the target module.
            ->setDefault('autocomplete_mode', Autocomplete::Auto)
            ->setAllowedTypes('autocomplete_mode', Autocomplete::class)
            ->setDefaults([
                'placeholder' => '—',
                // **Read once per request now, not once per form** (XIV-87).
                // This used to resolve the list here and said, at length, why it
                // deliberately kept no memo: records appear between one form and
                // the next, and a memo keyed by module would hand the older
                // answer to the newer form, whose submitted id is then not among
                // its own choices.
                //
                // That reasoning was right and its conclusion has expired. The
                // lifetime it could not express is expressible since XIV-54 —
                // {@see CandidateLists} is cleared on `kernel.reset`, so it
                // cannot cross the request boundary the objection was about — and
                // the case that actually hurt is the one nobody had measured: a
                // collection row is a form, so five hundred order lines built
                // this picker five hundred times. XIV-68 put a number on it.
                //
                // The lazy option stays, and still earns its place: a form whose
                // reference field is never rendered still asks for nothing — and
                // a field that autocompletes asks for nothing either, because
                // {@see self::readCandidates()} answers that case without a list.
                'candidates' => fn (Options $options): array => $this->readCandidates(
                    (string) $options['target_module'],
                    $options['target_variant'] === null ? null : (string) $options['target_variant'],
                    $options['autocomplete_mode'] instanceof Autocomplete ? $options['autocomplete_mode'] : Autocomplete::Auto,
                ),
                'choices' => static fn (Options $options): array => $options['candidates']['choices'],
                // **Null unless somebody is going to type**, because ChoiceType
                // reaches for a loader before it reaches for `choices` and a
                // loader on the select path would quietly replace the list this
                // form has always rendered.
                'choice_loader' => fn (Options $options): ?RecordChoiceLoader => $options['candidates']['autocomplete']
                    ? new RecordChoiceLoader(
                        $this->candidates,
                        (string) $options['target_module'],
                        $options['target_variant'] === null ? null : (string) $options['target_variant'],
                    )
                    : null,
                // The two the ux-autocomplete extension reads off any ChoiceType
                // (XIV-36). With a URL it attaches a Tom Select that pages
                // through the endpoint; without one it does nothing at all, so
                // the select path is untouched by the package being installed.
                'autocomplete' => static fn (Options $options): bool => $options['candidates']['autocomplete'],
                'autocomplete_url' => fn (Options $options): ?string => $options['candidates']['autocomplete']
                    ? $this->searchUrl->forModule(
                        (string) $options['target_module'],
                        $options['target_variant'] === null ? null : (string) $options['target_variant'],
                    )
                    : null,
                // One character, rather than the package's default of three. A
                // module whose records are named by a code — an article number,
                // a customer number — is one where the first character already
                // narrows the list usefully, and the first page arrives on focus
                // anyway, so this only decides how soon typing starts to help.
                'min_characters' => 1,
                // The dropdown holds what a page of the endpoint returns. Any
                // smaller and scrolling would stop before the page it has, which
                // is how an infinite scroll comes to look finite.
                'max_results' => RecordCandidates::PER_PAGE,
                // **A cap that says so** (XIV-35), and only where there still is
                // one. The docblock above always claimed a select showing the
                // first two hundred of nine thousand would be worse than one
                // that says so; the ceiling was implemented and the saying-so
                // was not, so a link that cannot be made looked exactly like a
                // record that does not exist. Under autocomplete there is no cap
                // to apologise for — the widget reaches everything the reader
                // may see — and below the ceiling there is nothing to explain,
                // since a sentence under every picker in the application would
                // be noise.
                'help' => static fn (Options $options): ?string => !$options['candidates']['autocomplete']
                    && $options['candidates']['total'] > \count($options['candidates']['choices'])
                        ? 'field.picker_truncated'
                        : null,
                'help_translation_parameters' => static fn (Options $options): array => [
                    '%shown%' => \count($options['candidates']['choices']),
                    '%total%' => $options['candidates']['total'],
                ],
            ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    /**
     * What this picker offers, and which of the two shapes it takes.
     *
     * **`always` reads nothing at all.** The widget's first page arrives from
     * the endpoint when somebody focuses it, so asking {@see CandidateLists} for
     * two hundred records here would be a page fetched to be thrown away — and
     * on a form whose only reference says `always`, it would be the only query
     * the picker made.
     *
     * Everything else goes through the memo, which is what makes `auto` free:
     * the number it decides on is the *real* total that list already had to
     * work out for the truncation notice, so deciding costs no query the form
     * was not making, and five hundred collection rows decide five hundred times
     * off one read.
     *
     * @return array{choices: array<string, int>, total: int, autocomplete: bool}
     */
    private function readCandidates(string $moduleKey, ?string $variant, Autocomplete $mode): array
    {
        if ($mode === Autocomplete::Always) {
            return ['choices' => [], 'total' => 0, 'autocomplete' => true];
        }

        $list = $this->lists->for($moduleKey, $variant);

        // The choices go with the select. Keeping them beside a search box would
        // put two hundred `<option>` elements per collection row back into the
        // page this option exists to lighten, to be ignored by a widget that
        // loads its own.
        return $mode->wants($list['total'])
            ? ['choices' => [], 'total' => $list['total'], 'autocomplete' => true]
            : [...$list, 'autocomplete' => false];
    }
}
