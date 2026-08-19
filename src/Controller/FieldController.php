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

namespace App\Controller;

use App\Tenant\Security\NoModulePermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\AssumesACountry;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\Autocompletes;
use Xivi\Core\Field\Enumerates;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\NeedsAnAnswer;
use Xivi\Core\Field\Numbers;
use Xivi\Core\Field\PointsAtAModule;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Field\UnknownFieldType;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Metadata\NumberingChange;
use Xivi\Core\Module\ModuleUpgrade;
use Xivi\Core\Numbering\AssignsNumbers;
use Xivi\Core\Numbering\CounterRefused;
use Xivi\Core\Numbering\NumberFormat;
use Xivi\Core\Phone\PhoneRegion;

/**
 * The metadata editor: a customer changing the shape of their own module.
 *
 * This is the engine's claim made usable. Everything else reads field
 * definitions; this writes them, and a field added here appears in the form, the
 * list, the validation and the filter bar without anything being deployed —
 * because all four are reading the same rows (§5.4).
 *
 * Admin only, which is the first thing in the application to need more than
 * "signed in". §8.4 says the real model is unfinished; changing what a module
 * *is* seemed the wrong place to keep waiting for it.
 *
 * It edits any shape, so a collection's fields are editable exactly like a
 * module's — the same reason there is one record repository (§5.1).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/m/{module}/fields', requirements: ['module' => '[a-z][a-z0-9_]*'])]
#[IsGranted('ROLE_ADMIN')]
#[NoModulePermission(
    'Changing what a module *is* is not one of the things you do *to* its '
    .'records, so it is not one of its permissions. A grant says who may edit a '
    .'contact; this decides what a contact has. Administrators only (§5.4).',
)]
final class FieldController extends AbstractController
{
    /**
     * The settings this form draws, and therefore the only ones it may change.
     *
     * A short list on purpose: everything else a field carries is declared by
     * its module and has no control here (XIV-26). These three are drawn for
     * every field whether or not they mean anything on it, which is what makes
     * them different from the per-type settings below — the better question
     * §5.4 asked, and the one PER_TYPE answers.
     */
    private const array SETTINGS = ['max_length', 'min', 'max'];

    /**
     * The settings whose control depends on the field's *type*, and what
     * declares each (XIV-36, XIV-27).
     *
     * Everything in SETTINGS above is a number and is drawn for every field
     * whether or not it means anything there. These are not: a `text` field has
     * nothing to autocomplete against and a `date` cannot be a document number,
     * and a control that does nothing is worse than an absent one — somebody
     * fills it in and waits for something to happen.
     *
     * So the *type* is asked, by implementing a capability interface. XIV-36
     * introduced that with a single `instanceof` and said in as many words that
     * generalising from one example would be guessing; XIV-27 made it two, which
     * is the point at which the shape §5.4 has always described — **a type
     * saying which of its options are the customer's to set** — could be written
     * from evidence rather than from imagination. This is that list, and it
     * predicted its own next entry: "a third option is a capability interface, a
     * line here and a control in the template, rather than another branch
     * through this class."
     *
     * **[XIV-114] is that third, and it cost exactly those three things.** A
     * phone field may assume a country other than the installation's, which is
     * {@see AssumesACountry}, one line below and one `<select>` in the field
     * table. Nothing in this class learned that a country exists. Two
     * predictions of a shape are a guess and three are a shape.
     *
     * What stays per option, deliberately, is *drawing* it: a select of three
     * fixed answers and a numbering pattern with a live preview have nothing in
     * common but the question "may this type have one". Generalising the control
     * as well would be inventing a widget description language to save two
     * `{% if %}`s.
     *
     * **The fourth and fifth are different in one way, and it is the way that
     * mattered** (XIV-144). Every entry above describes an option a field *may*
     * have, and every one of them has a good answer for a field that says
     * nothing. A `choice` field's options and a `reference`'s target have none:
     * without them the field is drawn, saved, validated and useless. So those two
     * types declare {@see NeedsAnAnswer} as well as their capability, this class
     * offers no type in its add-field select whose needs it cannot draw
     * ({@see self::configurable()}), and the engine refuses to write a definition
     * that leaves one unanswered. The list below is still the only place a
     * control is wired to a type.
     *
     * **Public, and that is the point rather than an oversight.** What makes this
     * defect recur is a field type registered with no control anywhere, and the
     * only way to notice that is to compare this list against the registry — so
     * {@see \App\Tests\Functional\Engine\EditorConfiguresEveryTypeTest} reads it
     * and goes red when the two disagree.
     *
     * @var array<string, class-string<FieldType>>
     */
    public const array PER_TYPE = [
        Autocomplete::OPTION => Autocompletes::class,
        NumberFormat::OPTION => Numbers::class,
        // The third, and the one that proves the list was worth declaring
        // (XIV-114): a phone field may assume a country other than the
        // installation's, and adding that was this line, a capability interface
        // and one control in the template. No branch through this class.
        PhoneRegion::OPTION => AssumesACountry::class,
        // The fourth and fifth (XIV-144). The list a `choice` field is a choice
        // between, and the module a `reference` points at — the two options the
        // editor offered a type for and then never asked about. Same cost as the
        // third: a line here and a control in the template. The options control
        // is a page rather than a cell, on the same terms as numbering's, so it
        // is named on the save that draws it and on no other.
        ChoiceFieldType::CHOICES => Enumerates::class,
        ReferenceFieldType::MODULE => PointsAtAModule::class,
    ];

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly MetadataEditor $editor,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly TranslatorInterface $translator,
        private readonly NumberingChange $numbering,
        private readonly ModuleUpgrade $upgrade,
    ) {
    }

    #[Route('', name: 'field_index', methods: ['GET'])]
    public function index(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        return $this->render('field/index.html.twig', [
            'module' => $definition,
            'shapes' => self::shapesOf($definition),
            // Every registered type, because this is what the *table* names a
            // field's type from and a field of a type this editor cannot
            // configure still has to say what it is.
            'types' => $this->fieldTypes->all(),
            // What may be *added*, which since XIV-144 is not the same list. A
            // type whose needs this form draws no control for cannot be
            // configured here, so offering it would be offering a field that
            // cannot be finished — the defect this ticket is named after.
            'addable' => $this->addableTypes(),
            // Which types offer which per-type control, as lists of type keys
            // (XIV-36, XIV-27). Resolved here rather than in the template
            // because Twig has no `instanceof`, and giving it one so a page
            // could ask what a service implements would be a worse answer than
            // a list of strings.
            'settable' => $this->settableByType(),
            'autocompleteChoices' => Autocomplete::settable(),
            // Every country there is, named in the language being read (XIV-114).
            // From symfony/intl rather than a list kept here, for the reason the
            // currency and timezone pickers give: a copy of the country list
            // maintained by hand is a copy that is wrong.
            'regionChoices' => Countries::getNames($request->getLocale()),
            // And every module this customer has, for the one option whose
            // answer is another module (XIV-144). Their own labels rather than
            // the blueprints' — §6.1 says the installed definition is the truth,
            // and a customer who renamed Contacts to Kunden should be choosing
            // "Kunden" here.
            'moduleChoices' => $this->installedModules(),
            // Which fields are drawn, saved, validated and useless: a choice
            // field with no options, a reference with nothing to point at
            // (XIV-144). Nothing new can reach this state — the engine refuses
            // it — so what this marks is a field that predates the rule or one a
            // module wrote itself, and it is marked rather than left because
            // §8.3.1's whole argument is that silence is the worse failure.
            'unfinished' => $this->unfinishedIn($definition),
            // And which *fields* are actually numbered, which the type cannot
            // answer: the link to the numbering page appears on those, and the
            // page itself refuses everything else on the same test.
            'numbered' => $this->numberedIn($definition),
            // And which could be, which is XIV-91's whole addition to this page:
            // the link now appears on a plain text field too, and says something
            // different when it does.
            'numberable' => $this->numberableIn($definition),
            // How many things this module's blueprint has grown that this
            // customer has not got (XIV-70). A count and not the list, because
            // this page is not where the offer is read — it is where somebody
            // who came to change their fields finds out there is something to
            // read. The offer itself, with the argument for each one and the
            // dismissed ones beside it, is ModuleUpgradeController's.
            'additions' => \count($this->upgrade->available($definition)),
        ]);
    }

    /**
     * Numbering, on its own page rather than in a cell of the field table
     * (XIV-27).
     *
     * The table is a row per field and a control per column, which fits a
     * checkbox and a select and does not fit this. What a customer is deciding
     * here is a small language with quiet failure modes — a pattern that numbers
     * nothing, a width too narrow to keep sorting, a `{year}` that silently
     * changes which counter the next number comes out of — and the answer to
     * every one of those is the same: **show them the number it will produce**,
     * beside a sentence naming the counter it will come from. That needs room,
     * and it needs to re-render as somebody types, which is what
     * {@see \App\Twig\Components\FieldNumbering} is for.
     *
     * **Reachable for a field that is not numbered yet too** (XIV-91), which is
     * the one thing XIV-27 deliberately withheld. The same page, because it is
     * the same subject: a pattern, the number it produces and the counter it
     * comes from. What is added when the field has no numbering is the part that
     * is about *records* rather than about the pattern — how many of them have
     * nothing in this field and would be given a number, and how many already
     * hold something and would keep it.
     *
     * **Here rather than as a checkbox in the field table**, and that is a
     * decision rather than a place things happened to fit. The row in that table
     * is a control per column and every one of them is instantaneous and
     * reversible: tick "listed", untick it, nothing happened. Numbering is
     * neither. It writes into records that already exist, once, and it has to
     * say how many before it does — which is a paragraph and a confirmation, not
     * a checkbox, and putting it in the row would make the most consequential
     * change on the page look like the cheapest one.
     *
     * The two counts are handed to the component as props rather than read by
     * it. They do not depend on the pattern somebody is typing, so reading them
     * per keystroke would be two table scans for an answer that cannot have
     * changed; the scan that *does* depend on the pattern is deferred to the
     * confirmation, which is the one request that can afford it.
     */
    #[Route('/{field}/numbering', name: 'field_numbering', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function numbering(string $module, int $field): Response
    {
        $definition = $this->definition($module);
        $target = $this->numberingField($definition, $field);

        return $this->render('field/numbering.html.twig', [
            'module' => $definition,
            'field' => $target,
            'numbered' => NumberFormat::of($target) !== null,
            'blank' => $this->editor->recordsMissing($target),
            'held' => $this->editor->recordsHolding($target),
        ]);
    }

    /**
     * What turning numbering on would do, before it is done (XIV-91).
     *
     * A page of its own between typing a pattern and living with it, and it
     * exists for one reason: the backfill writes numbers into records that
     * already exist, in creation order, once, with no undo. §4.1 sets the tone
     * for that — name what is about to happen, name how much of it there is, and
     * default to no — and it is the tone rather than the mechanism that is being
     * copied here.
     *
     * It says four things a customer cannot work out for themselves: how many
     * records get a number, what the first and last of them will be called, how
     * many keep a value they already have, and that a number typed in before now
     * has been found and the counter moved above it. All four come from the same
     * computation the real run uses ({@see NumberingChange::plan()}), so the page
     * is not a description of the operation but the operation asked not to
     * commit.
     *
     * A POST because it carries the pattern that was typed into the live control
     * on the page before, and because a GET that scanned a customer's whole
     * records table would be a link somebody could put in a crawler.
     */
    #[Route('/{field}/numbering/start', name: 'field_numbering_start', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function confirmNumbering(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);
        $target = $this->numberableField($definition, $field);
        $pattern = trim((string) $request->request->get(NumberFormat::OPTION));
        $format = NumberFormat::parse($pattern);

        if (!$this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token')) || $format === null) {
            // A pattern that numbers nothing is refused rather than confirmed,
            // with the sentence the write path would have used — the page it
            // came from disables the button for exactly this, so arriving here
            // means the form was posted around it.
            $this->addFlash('warning', MetadataChangeRefused::patternNumbersNothing($pattern)
                ->translatable()
                ->trans($this->translator));

            return $this->redirectToRoute('field_numbering', ['module' => $module, 'field' => $field]);
        }

        return $this->render('field/numbering_start.html.twig', [
            'module' => $definition,
            'field' => $target,
            'pattern' => $pattern,
            'plan' => $this->numbering->plan($definition, $target, $format, new \DateTimeImmutable()),
        ]);
    }

    /**
     * And doing it, once somebody has said the word (XIV-91).
     *
     * The confirmation checkbox is required here rather than only in the
     * template. A required attribute is a courtesy to somebody using the page
     * and nothing at all to a form posted around it, and what is on the other
     * side of this call is a write into every record of a module that cannot be
     * taken back — so the rule is on the server, where it holds for every caller
     * rather than for the ones who arrived through the front door.
     *
     * Everything else is {@see NumberingChange::start()}'s, in one transaction:
     * a refusal, a browser closing or a backfill too large to finish leaves the
     * field exactly as unnumbered as it was.
     */
    #[Route('/{field}/numbering/on', name: 'field_numbering_on', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function startNumbering(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))
            && $request->request->getBoolean('confirm')
        ) {
            $target = $this->numberableField($definition, $field);
            $pattern = trim((string) $request->request->get(NumberFormat::OPTION));
            $format = NumberFormat::parse($pattern);

            try {
                if ($format === null) {
                    throw MetadataChangeRefused::patternNumbersNothing($pattern);
                }

                $done = $this->numbering->start($definition, $target, $format, new \DateTimeImmutable());

                // The figures come back from the run rather than from the page
                // that was agreed to: a record added between the two is one more
                // record numbered, and the sentence somebody reads afterwards
                // should be about what happened.
                $this->addFlash('success', $this->translator->trans('flash.numbering_started', [
                    '%field%' => $target->getLabel(),
                    '%count%' => $done->writes(),
                ]));
            } catch (MetadataChangeRefused|CounterRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));

                return $this->redirectToRoute('field_numbering', ['module' => $module, 'field' => $field]);
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    /**
     * What turning numbering off means, said before it happens (XIV-91).
     *
     * Nothing is destroyed by this and it is still confirmed, because "nothing
     * is destroyed" is precisely the part that would be assumed wrongly in
     * either direction. Somebody switching numbering off may well expect the
     * numbers to go with it — they do not, they are on documents customers are
     * holding — and may not expect the field to become an ordinary text box that
     * anybody can type a duplicate into, which it does.
     *
     * A GET confirmation like the one in front of removing a field, because
     * there is nothing to carry: the only input is the decision.
     */
    #[Route('/{field}/numbering/off', name: 'field_numbering_confirm_off', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function confirmStopNumbering(string $module, int $field): Response
    {
        $definition = $this->definition($module);
        $target = $this->numberedField($definition, $field);

        return $this->render('field/numbering_stop.html.twig', [
            'module' => $definition,
            'field' => $target,
            'numbered' => $this->editor->recordsHolding($target),
        ]);
    }

    #[Route('/{field}/numbering/off', name: 'field_numbering_off', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function stopNumbering(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            $target = $this->numberedField($definition, $field);
            $this->numbering->stop($target);

            $this->addFlash('success', $this->translator->trans('flash.numbering_stopped', [
                '%field%' => $target->getLabel(),
            ]));
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    /**
     * Saving both halves of it, or neither (XIV-27).
     *
     * **The counter first.** It is the half that can be refused for a reason the
     * customer has to act on — a number at or below one already given out — and
     * doing it first means a refusal leaves the definition exactly as it was.
     * The other order would save the pattern, fail on the counter, and leave
     * somebody looking at a page that had done half of what they asked without
     * saying which half.
     *
     * Neither half is validated here beyond finding the counter to write to. The
     * pattern is checked by {@see MetadataEditor}, on the write path, where
     * every other caller meets it too; a controller that checked it as well
     * would be a second copy of the rule, and the second copy is the one that
     * gets forgotten.
     */
    #[Route('/{field}/numbering', name: 'field_numbering_save', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function saveNumbering(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            $target = $this->numberedField($definition, $field);
            $pattern = trim((string) $request->request->get(NumberFormat::OPTION));

            try {
                $this->restartCounter($definition, $target, $pattern, trim((string) $request->request->get('next_value')));

                $this->editor->updateField(
                    field: $target,
                    label: $target->getLabel(),
                    required: $target->isRequired(),
                    unique: $target->isUnique(),
                    filterable: $target->isFilterable(),
                    listed: $target->isListed(),
                    title: $target->isTitle(),
                    position: $target->getPosition(),
                    // The one thing this page changes. Everything else is the
                    // field as it already is, and everything the page has never
                    // heard of is left alone by the merge (XIV-26) — this form
                    // draws one control and therefore names one option.
                    options: [NumberFormat::OPTION => $pattern],
                    width: $target->getWidth(),
                );

                $this->addFlash('success', $this->translator->trans('flash.numbering_saved', [
                    '%field%' => $target->getLabel(),
                ]));
            } catch (MetadataChangeRefused|CounterRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));

                // Back to the numbering page rather than to the list: what was
                // typed was refused, and the page it was typed on is where the
                // explanation makes sense.
                return $this->redirectToRoute('field_numbering', ['module' => $module, 'field' => $field]);
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    /**
     * Winding the counter forward, when a number was asked for.
     *
     * Blank means "leave the counter alone", which is what almost every save of
     * this page means: changing a prefix has nothing to do with where the
     * sequence has got to. A counter is not a setting with a current value the
     * form round-trips — it is a fact about what has been given out — so the
     * control is empty until somebody deliberately types in it.
     *
     * The counter it writes to is the one the **new** pattern implies, not the
     * old one. That is the whole of the surprise this page exists to make
     * visible: adding `{year}` moves to a counter of its own, and somebody
     * setting 1043 while adding it means 1043 in the new numbering.
     *
     * Through {@see NumberingChange::windForward()} rather than straight at the
     * allocator (XIV-91): a field that used to be typed into by hand can carry a
     * number no counter ever gave out, and the counter's own guard compares
     * against the counter. Both checks happen, the second one is still the
     * atomic statement, and neither is this controller's to make.
     *
     * @throws CounterRefused
     */
    private function restartCounter(
        ModuleDefinition $module,
        FieldDefinition $field,
        string $pattern,
        string $next,
    ): void {
        $format = NumberFormat::parse($pattern);

        if ($next === '' || !ctype_digit($next) || $format === null) {
            // Three ways of having nothing to do, and none of them is this
            // method's to complain about. An unusable pattern is refused a few
            // lines below with a sentence about patterns, which is the thing
            // that was actually wrong. Anything that is not digits is a number
            // control that has been edited by hand — `-5` or `four` — and it is
            // treated as blank rather than clamped, the same way a width is
            // (see widthFrom): a form that quietly turns nonsense into a value
            // tells somebody they got what they asked for. Zero *is* digits and
            // goes through, to be refused by the counter as the wind-back it is.
            return;
        }

        $this->numbering->windForward($module, $field, $format, (int) $next, new \DateTimeImmutable());
    }

    /**
     * The list a `choice` field is a choice between, on a page of its own
     * (XIV-144).
     *
     * **A page for the same reason numbering is one**, and the argument is
     * §5.4's rather than a new one: every control in the field table is a cell,
     * instantaneous and reversible — tick "on list", untick it, nothing
     * happened. A list is neither. It has a row per option and it needs one, it
     * has a rename that must not move a record and a removal that may not
     * happen at all, and the sentence explaining why an option cannot go is
     * three lines long. Putting that in a table cell would make the change with
     * the most consequences look like the cheapest one on the row.
     *
     * The **value** is shown and not editable, which is the one thing on this
     * page somebody has to be told rather than shown: it is what every record
     * holds, the label beside it is what everybody reads, and renaming the label
     * is free precisely because the two are different things.
     *
     * The add form asks for the same list in a textarea, because a choice field
     * with no options is not something the engine will write — so the customer
     * meets the question once when the field is created and comes back here to
     * change the answer.
     */
    #[Route('/{field}/options', name: 'field_options', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function options(string $module, int $field): Response
    {
        $definition = $this->definition($module);
        $target = $this->choiceField($definition, $field);

        return $this->render('field/options.html.twig', [
            'module' => $definition,
            'field' => $target,
            'choices' => ChoiceFieldType::choicesOf($target),
            // How many records hold each option, beside the option. The count is
            // why a removal will be refused, and reading it here rather than in
            // the refusal is the difference between somebody planning a change
            // and somebody being told no after trying it.
            'held' => $this->editor->valuesHeldBy($target),
        ]);
    }

    /**
     * Saving it: what was renamed, what was added, and what somebody ticked to
     * remove (XIV-144).
     *
     * Every option the page drew is named on the way back, which is the same
     * contract every other control in this editor has — the list that arrives is
     * the list the field ends up with, and an option missing from it is an
     * option removed rather than one left alone. That is what lets a removal be
     * refused at all: a merge that only ever added could not tell the difference
     * between "take this away" and "I did not mention it".
     *
     * Nothing about *whether* a removal is allowed is decided here.
     * {@see MetadataEditor} counts the records holding it and refuses with the
     * number, on the write path, where the importer and the console meet the
     * same rule.
     */
    #[Route('/{field}/options', name: 'field_options_save', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function saveOptions(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            $target = $this->choiceField($definition, $field);

            try {
                $this->editor->updateField(
                    field: $target,
                    label: $target->getLabel(),
                    required: $target->isRequired(),
                    unique: $target->isUnique(),
                    filterable: $target->isFilterable(),
                    listed: $target->isListed(),
                    title: $target->isTitle(),
                    position: $target->getPosition(),
                    // The one thing this page changes, and everything else about
                    // the field is handed back as it already is — including every
                    // option this form has never heard of, which the merge leaves
                    // alone (XIV-26).
                    options: $this->choicesFrom($request, $target->getType(), self::keptFrom($request, $target)),
                    width: $target->getWidth(),
                );

                $this->addFlash('success', $this->translator->trans('flash.options_saved', [
                    '%field%' => $target->getLabel(),
                ]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));

                // Back to the page it was decided on rather than to the list: a
                // refusal about an option is only actionable next to the options.
                return $this->redirectToRoute('field_options', ['module' => $module, 'field' => $field]);
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    /**
     * The options that survive this save, with whatever they have been renamed
     * to.
     *
     * A removal is an option the page sent back with its box ticked, and a
     * rename is a label that arrived different from the one that went out. Both
     * are read against the field's *current* list rather than against the form,
     * so a value invented in a hand-edited form adds nothing: what is not
     * already an option cannot be kept.
     *
     * A label emptied out keeps the old one. A blank label would render as a
     * blank line in a dropdown, which is indistinguishable from the placeholder
     * and is nobody's intention — and the option cannot simply be dropped
     * either, because dropping it is the operation with a conversation attached.
     *
     * @return array<string, string> value => label
     */
    private static function keptFrom(Request $request, FieldDefinition $field): array
    {
        /** @var array<string, mixed> $labels */
        $labels = $request->request->all('label');
        /** @var array<string, mixed> $remove */
        $remove = $request->request->all('remove');

        $kept = [];

        foreach (ChoiceFieldType::choicesOf($field) as $value => $label) {
            if (\array_key_exists($value, $remove)) {
                continue;
            }

            $renamed = trim((string) ($labels[$value] ?? ''));
            $kept[$value] = $renamed === '' ? $label : $renamed;
        }

        return $kept;
    }

    /**
     * A field whose options a customer may edit, by id, or a 404.
     *
     * The type is asked rather than named, exactly as the numbering page asks:
     * this URL is not found for a date field because a date field has no list,
     * and it will be found for whatever type declares {@see Enumerates} next
     * without this method being edited.
     */
    private function choiceField(ModuleDefinition $module, int $id): FieldDefinition
    {
        $field = $this->field($module, $id);

        if (!$this->offers(ChoiceFieldType::CHOICES, $field->getType())) {
            throw $this->createNotFoundException(sprintf(
                'Field %d of "%s" is a "%s", which has no list of options to edit.',
                $id,
                $module->getKey(),
                $field->getType(),
            ));
        }

        return $field;
    }

    /**
     * What a customer calls one of their own shapes (XIV-8).
     *
     * Their module arrived named by whatever language it was installed in, and
     * "Contacts" is not the word a German office uses. Renaming it is the same
     * kind of change as relabelling a field, so it lives on the same screen and
     * goes through the same editor.
     */
    #[Route('/{shape}/rename', name: 'shape_rename', requirements: ['shape' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function rename(string $module, int $shape, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            try {
                $target = $this->shape($definition, $shape);
                $this->editor->renameShape($target, (string) $request->request->get('label'));

                $this->addFlash('success', $this->translator->trans('flash.shape_renamed', [
                    '%shape%' => $target->getLabel(),
                ]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    #[Route('/add', name: 'field_add', methods: ['POST'])]
    public function add(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            $shape = $this->shape($definition, (int) $request->request->get('shape'));

            try {
                $field = $this->editor->addField(
                    shape: $shape,
                    key: (string) $request->request->get('key'),
                    label: (string) $request->request->get('label'),
                    type: (string) $request->request->get('type'),
                    required: $request->request->getBoolean('required'),
                    unique: $request->request->getBoolean('unique'),
                    filterable: $request->request->getBoolean('filterable'),
                    listed: $request->request->getBoolean('listed'),
                    title: $request->request->getBoolean('title'),
                    options: [
                        ...$this->optionsFrom($request, (string) $request->request->get('type')),
                        // The list a choice field is added with (XIV-144). Here
                        // and not in optionsFrom() because this is one of the two
                        // forms that draws it; the row in the table is the one
                        // that does not, and a form that does not draw a setting
                        // does not name it.
                        ...$this->choicesFrom($request, (string) $request->request->get('type')),
                    ],
                );

                $this->addFlash('success', $this->translator->trans('flash.field_added', ['%field%' => $field->getLabel()]));
                // UnknownFieldType too: the select is built from the registry, so a
                // type it does not know means a tampered form, which is a message
                // rather than a stack trace.
            } catch (MetadataChangeRefused|UnknownFieldType $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    #[Route('/{field}', name: 'field_update', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function update(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            $target = $this->field($definition, $field);

            try {
                $this->editor->updateField(
                    field: $target,
                    label: (string) $request->request->get('label'),
                    required: $request->request->getBoolean('required'),
                    unique: $request->request->getBoolean('unique'),
                    filterable: $request->request->getBoolean('filterable'),
                    listed: $request->request->getBoolean('listed'),
                    title: $request->request->getBoolean('title'),
                    position: $request->request->getInt('position', $target->getPosition()),
                    options: $this->optionsFrom($request, $target->getType()),
                    // Blank means "however wide this kind of field usually is"
                    // (XIV-43), which is a real answer and not a missing one.
                    width: self::widthFrom($request),
                );

                $this->addFlash('success', $this->translator->trans('flash.field_saved', ['%field%' => $target->getLabel()]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    /**
     * The width a form sent, or null for "follow the field type" (XIV-43).
     *
     * An empty box is the default rather than a zero: the control offers a blank
     * option and that blank is what almost every field should keep. Anything
     * outside 1-12 is nonsense from a hand-edited form and is treated as blank
     * rather than clamped, because a form that quietly turns 40 into 12 tells
     * somebody they got what they asked for.
     */
    private static function widthFrom(Request $request): ?int
    {
        $width = trim((string) $request->request->get('width'));

        if ($width === '' || !ctype_digit($width)) {
            return null;
        }

        $width = (int) $width;

        return $width >= 1 && $width <= 12 ? $width : null;
    }

    /**
     * The confirmation, which exists to say what removing a field does *not* do.
     *
     * Somebody clicking delete reasonably assumes the data goes with it. It does
     * not, so this says so, and says how many records are holding a value.
     */
    #[Route('/{field}/delete', name: 'field_confirm_delete', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function confirmDelete(string $module, int $field): Response
    {
        $definition = $this->definition($module);
        $target = $this->field($definition, $field);

        return $this->render('field/delete.html.twig', [
            'module' => $definition,
            'field' => $target,
            'holding' => $this->editor->recordsHolding($target),
        ]);
    }

    #[Route('/{field}/delete', name: 'field_delete', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function delete(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            $target = $this->field($definition, $field);

            try {
                $this->editor->removeField($target);
                $this->addFlash('success', $this->translator->trans('flash.field_removed', ['%field%' => $target->getLabel()]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_index', ['module' => $module]);
    }

    /**
     * The module and its collections, in the order they are edited.
     *
     * @return list<ShapeDefinition>
     */
    private static function shapesOf(ModuleDefinition $module): array
    {
        return [$module, ...array_values($module->getCollections()->toArray())];
    }

    /**
     * The per-type settings this form draws, and only those (XIV-26).
     *
     * **Every one of them is named on every save, cleared ones as null**, and
     * nothing else is named at all. That is the contract MetadataEditor merges
     * on: what the form does not mention it does not touch, so a field's
     * `choices`, its `module`, what it inherits and how it is numbered all
     * survive an edit this form has no idea it is next to.
     *
     * Blank means gone rather than absent, so a limit somebody has emptied out
     * really is emptied — a form that could only ever add a setting would be the
     * opposite bug.
     *
     * The autocomplete setting joins them for the types that offer it (XIV-36),
     * and is named on exactly the same terms: the select always sends a value,
     * blank means "decide from the count", and blank therefore clears rather
     * than leaves alone. A type that does not offer it is not named at all, so a
     * text field's save says nothing about autocomplete and could not clear one
     * even if something had put it there.
     *
     * @return array<string, int|string|null>
     */
    private function optionsFrom(Request $request, string $type): array
    {
        $options = [];

        foreach (self::SETTINGS as $option) {
            $value = trim((string) $request->request->get($option, ''));
            $options[$option] = $value === '' ? null : (int) $value;
        }

        if ($this->offers(Autocomplete::OPTION, $type)) {
            // Through the enum rather than trusted: the control offers three
            // answers and anything else is a hand-edited form, which should read
            // as "no opinion" rather than land a word in the definitions that
            // nothing knows how to interpret.
            $chosen = Autocomplete::tryFrom(trim((string) $request->request->get(Autocomplete::OPTION, '')));
            $options[Autocomplete::OPTION] = $chosen === null || $chosen === Autocomplete::Auto
                ? null
                : $chosen->value;
        }

        if ($this->offers(PhoneRegion::OPTION, $type)) {
            // Blank is the answer almost every phone field wants — "whichever
            // country this installation is in" — and it is therefore what an
            // empty select clears back to, on the same terms as the width and
            // the autocomplete control above. Checked against symfony/intl
            // rather than trusted, for the same reason the autocomplete select
            // is checked against its enum: the control offers the countries
            // there are, so anything else is a hand-edited form, and the honest
            // response to one of those is to change nothing.
            $chosen = strtoupper(trim((string) $request->request->get(PhoneRegion::OPTION, '')));
            $options[PhoneRegion::OPTION] = Countries::exists($chosen) ? $chosen : null;
        }

        if ($this->offers(ReferenceFieldType::MODULE, $type) && $request->request->has(ReferenceFieldType::MODULE)) {
            // A select of this customer's own modules — and the one control here
            // whose absence is read differently from its emptiness (XIV-144).
            //
            // Every other setting on this form is "named on every save, cleared
            // when blank", because every other setting has a meaningful empty
            // state: no maximum, no country, decide the search box from the
            // count. A reference's target has none. Blank is not a way of
            // pointing a field somewhere, it is a field that does nothing — so
            // there is no clearing to do, and a request that does not carry the
            // control at all is a form that did not draw one rather than
            // somebody emptying it. A blank that *is* sent still goes through as
            // null and is refused by the engine, which is the add form's empty
            // option and the sentence it earns.
            //
            // Not checked against the installed list here: that check is on the
            // write path, where the console and the importer meet it too, and a
            // second copy in a controller is the copy that gets forgotten.
            $target = trim((string) $request->request->get(ReferenceFieldType::MODULE, ''));
            $options[ReferenceFieldType::MODULE] = $target === '' ? null : $target;
        }

        return $options;
    }

    /**
     * The options a `choice` field is a choice between, as the form sends them
     * (XIV-144).
     *
     * **Separate from {@see self::optionsFrom()} because it is drawn in two
     * places and not in the third**, which is the same shape numbering has: the
     * add form asks for the list, its own page edits it, and the row in the
     * field table draws no control for it at all. A form that does not draw it
     * must not name it — naming it would clear the list on the first save of an
     * unrelated checkbox, which is precisely the accident XIV-26 was about.
     *
     * Labels in, keys derived. Somebody adding "Pallet" to a list of units is
     * not deciding what the database calls it, and asking them to would be
     * asking them to understand a distinction that only matters when it is too
     * late to change ({@see ChoiceFieldType::valueFor()}).
     *
     * @param array<string, string> $existing what the field already has, so a key derived
     *                                        now cannot collide with one records hold
     *
     * @return array<string, mixed> either the one option, or nothing at all
     */
    private function choicesFrom(Request $request, string $type, array $existing = []): array
    {
        if (!$this->offers(ChoiceFieldType::CHOICES, $type)) {
            return [];
        }

        $choices = $existing;

        foreach (preg_split('/\R/', (string) $request->request->get(ChoiceFieldType::CHOICES, '')) ?: [] as $line) {
            $label = trim($line);

            if ($label === '') {
                // Blank lines are how a textarea is typed in, not an option
                // called nothing.
                continue;
            }

            $choices[ChoiceFieldType::valueFor($label, $choices)] = $label;
        }

        // Named even when it is empty, so that a save which was *asked* to set
        // the list and given nothing is refused by the engine rather than
        // quietly leaving the field as it was.
        return [ChoiceFieldType::CHOICES => $choices];
    }

    /**
     * The types the add-field select offers, which is not every registered one
     * (XIV-144).
     *
     * **The acceptance criterion this ticket turns on**, and it is a statement
     * about the registry rather than about today's list of types: a type this
     * form cannot ask the customer's question for is not offered, whatever it
     * is. Today every registered type passes and the list is the whole registry
     * again; the day somebody writes a type that needs an answer nobody has
     * built a control for, it disappears from the select instead of being
     * offered broken, and
     * {@see \App\Tests\Functional\Engine\EditorConfiguresEveryTypeTest} says so
     * out loud before anybody ships it.
     *
     * @return array<string, FieldType>
     */
    private function addableTypes(): array
    {
        return array_filter($this->fieldTypes->all(), self::configurable(...));
    }

    /**
     * Whether this form can ask everything a type cannot work without.
     *
     * Static and public so that the test can plant a violation against it — a
     * hypothetical type needing something nobody drew — without a container, a
     * tenant or a request. The rule is one line of arithmetic over two
     * declarations that live in different layers: what the type says it needs,
     * and what {@see self::PER_TYPE} says this form draws for it. A need that is
     * not in that list, or is in it under a capability this type does not
     * declare, is a question the editor has no way of asking.
     */
    public static function configurable(FieldType $type): bool
    {
        if (!$type instanceof NeedsAnAnswer) {
            return true;
        }

        foreach ($type->needs() as $option) {
            $capability = self::PER_TYPE[$option] ?? null;

            if ($capability === null || !$type instanceof $capability) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every module this customer has, as key => their own label for it.
     *
     * What a reference may point at. Sorted by label rather than by key, because
     * it is read as a list of words; the module's own key is what is stored and
     * is not what anybody is choosing between.
     *
     * @return array<string, string>
     */
    private function installedModules(): array
    {
        $modules = [];

        foreach ($this->metadata->all() as $module) {
            $modules[$module->getKey()] = $module->getLabel();
        }

        asort($modules);

        return $modules;
    }

    /**
     * The ids of the fields whose type needs something this one has not got
     * (XIV-144).
     *
     * Read off the type rather than listed here, so it covers whatever the next
     * {@see NeedsAnAnswer} turns out to be without this method learning what a
     * choice is.
     *
     * @return list<int>
     */
    private function unfinishedIn(ModuleDefinition $module): array
    {
        $unfinished = [];

        foreach (self::shapesOf($module) as $shape) {
            foreach ($shape->getFields() as $field) {
                $id = $field->getId();
                $type = $this->fieldTypes->has($field->getType()) ? $this->fieldTypes->get($field->getType()) : null;

                if ($id === null || !$type instanceof NeedsAnAnswer) {
                    continue;
                }

                foreach ($type->needs() as $option) {
                    $answer = $field->getOption($option);

                    if ($answer === null || $answer === '' || $answer === []) {
                        $unfinished[] = $id;

                        break;
                    }
                }
            }
        }

        return $unfinished;
    }

    /**
     * Which types offer which per-type option, resolved once from PER_TYPE.
     *
     * The template asks `field.type in settable.autocomplete`, which is a list
     * of strings rather than a service question — Twig has no `instanceof`, and
     * it should not grow one so that a page can interrogate the container.
     *
     * @return array<string, list<string>>
     */
    private function settableByType(): array
    {
        $offered = [];

        foreach (self::PER_TYPE as $option => $capability) {
            $offered[$option] = [];

            foreach ($this->fieldTypes->all() as $key => $type) {
                if ($type instanceof $capability) {
                    $offered[$option][] = (string) $key;
                }
            }
        }

        return $offered;
    }

    /**
     * Whether a type key names one that offers this option.
     *
     * An unknown key is a no rather than an exception: `add` already answers for
     * a tampered type select with a message, and this runs while building the
     * arguments for that same call.
     */
    private function offers(string $option, string $type): bool
    {
        return \in_array($type, $this->settableByType()[$option] ?? [], true);
    }

    /**
     * The ids of the fields whose numbering a customer may edit (XIV-27).
     *
     * The two narrowings in {@see idsOf()}, plus the one that makes this list
     * what it is: **a pattern that is already a sequence.** Whether a field is
     * numbered is not a fact about its type, and since XIV-91 it is not the same
     * question as whether it *could* be — see {@see numberableIn()} for the other
     * half, which is everything this excludes.
     *
     * @return list<int>
     */
    private function numberedIn(ModuleDefinition $module): array
    {
        return $this->idsOf($module, fn (FieldDefinition $field): bool => NumberFormat::of($field) !== null);
    }

    /**
     * The ids of the fields a customer could *start* numbering (XIV-91).
     *
     * The same two narrowings as above — the module's own shape, a type that
     * declares {@see Numbers} — minus the third, plus one that is new and is the
     * ticket's answer to what a derived field means here:
     *
     *  * **not numbered already**, which is the whole difference. That field has
     *    a pattern to edit rather than numbering to take up.
     *  * **not derived.** A derived field is the engine's ({@see
     *    FieldDefinition::isDerived()}): an order's total, an invoice's due date,
     *    a value computed on every save by something that owns it. Numbering one
     *    would mean two derivers with an opinion about the same column, and the
     *    one that ran second would win by accident. Numbering *makes* a field
     *    derived, which is the same statement read forwards — so what this list
     *    excludes is a field that already belongs to somebody.
     *
     * System-ness is deliberately **not** a narrowing. A module's own text field
     * is still the customer's data in the customer's copy of the module (§6.1),
     * and refusing to number `contact.reference` because a blueprint created it
     * would be inventing a rule §5.4 does not have — the rule it does have is
     * about *removing* a module's field, which orphans values, and this creates
     * none.
     *
     * @return list<int>
     */
    private function numberableIn(ModuleDefinition $module): array
    {
        return $this->idsOf(
            $module,
            static fn (FieldDefinition $field): bool => NumberFormat::of($field) === null && !$field->isDerived(),
        );
    }

    /**
     * The module's own numbering-capable fields that also satisfy something else.
     *
     * The two narrowings both lists share, in one place: {@see AssignsNumbers}
     * walks a module's fields and nothing else, so a numbered field on an order
     * *line* would be a page promising a number no save would ever assign; and a
     * type has to declare {@see Numbers} before any of this is offered on it.
     *
     * @param callable(FieldDefinition): bool $and
     *
     * @return list<int>
     */
    private function idsOf(ModuleDefinition $module, callable $and): array
    {
        $ids = [];

        foreach ($module->getFields() as $field) {
            $id = $field->getId();

            if ($id !== null && $this->offers(NumberFormat::OPTION, $field->getType()) && $and($field)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * A field whose numbering may be *edited*, by id, or a 404.
     *
     * Narrower than {@see numberingField()} on purpose: saving a pattern and
     * winding a counter forward are changes to numbering that already exists, so
     * a URL naming an ordinary text field is not found rather than being a way
     * to switch numbering on without passing the confirmation.
     */
    private function numberedField(ModuleDefinition $module, int $id): FieldDefinition
    {
        $field = $this->field($module, $id);

        if (!\in_array($id, $this->numberedIn($module), true)) {
            throw $this->createNotFoundException(
                sprintf('Field %d of "%s" is not numbered, so it has no numbering to edit.', $id, $module->getKey()),
            );
        }

        return $field;
    }

    /**
     * A field numbering may be *taken up* on, by id, or a 404 (XIV-91).
     *
     * The mirror of {@see numberedField()}, and both are narrow for the same
     * reason: the two halves of this page do different things and only one of
     * them writes into records. A field that is numbered already must not reach
     * the confirmation — it would offer to backfill a column the engine has been
     * filling all along — and a field that is not must not reach the save, which
     * would be a way to switch numbering on without passing the confirmation at
     * all.
     */
    private function numberableField(ModuleDefinition $module, int $id): FieldDefinition
    {
        $field = $this->field($module, $id);

        if (!\in_array($id, $this->numberableIn($module), true)) {
            throw $this->createNotFoundException(sprintf(
                'Field %d of "%s" is numbered already, or is not something that can be: numbering is offered on '
                . "a module's own text fields that nothing else fills in.",
                $id,
                $module->getKey(),
            ));
        }

        return $field;
    }

    /**
     * A field the numbering *page* is about: numbered, or able to be (XIV-91).
     *
     * Every route that reaches that page goes through this, so the link the
     * field list draws and the page it leads to cannot disagree about who is
     * allowed there — and a hand-typed URL naming a date field, a collection's
     * field or an order's total is still not found.
     */
    private function numberingField(ModuleDefinition $module, int $id): FieldDefinition
    {
        $field = $this->field($module, $id);

        if (!\in_array($id, $this->numberedIn($module), true)
            && !\in_array($id, $this->numberableIn($module), true)
        ) {
            throw $this->createNotFoundException(sprintf(
                'Field %d of "%s" is not numbered and cannot be: numbering is offered on a module\'s own text '
                . 'fields that nothing else fills in.',
                $id,
                $module->getKey(),
            ));
        }

        return $field;
    }

    /** A shape of *this* module, so an id from a form cannot reach another one. */
    private function shape(ModuleDefinition $module, int $id): ShapeDefinition
    {
        foreach (self::shapesOf($module) as $shape) {
            if ($shape->getId() === $id) {
                return $shape;
            }
        }

        throw $this->createNotFoundException(sprintf('No shape %d on "%s".', $id, $module->getKey()));
    }

    /** Likewise a field: an id in a URL is not a licence to edit another module's. */
    private function field(ModuleDefinition $module, int $id): FieldDefinition
    {
        foreach (self::shapesOf($module) as $shape) {
            foreach ($shape->getFields() as $field) {
                if ($field->getId() === $id) {
                    return $field;
                }
            }
        }

        throw $this->createNotFoundException(sprintf('No field %d on "%s".', $id, $module->getKey()));
    }

    private function definition(string $module): ModuleDefinition
    {
        try {
            return $this->metadata->get($module);
        } catch (ModuleNotInstalled $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }
}
