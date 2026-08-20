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
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\AssumesACountry;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\Autocompletes;
use Xivi\Core\Field\BoundsItsValues;
use Xivi\Core\Field\Enumerates;
use Xivi\Core\Field\ExcludesOverlaps;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\HoldsAFile;
use Xivi\Core\Field\HoldsSeveralValues;
use Xivi\Core\Field\LimitsItsLength;
use Xivi\Core\Field\NeedsAnAnswer;
use Xivi\Core\Field\Numbers;
use Xivi\Core\Field\PointsAtAList;
use Xivi\Core\Field\PointsAtAModule;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Field\UnknownFieldType;
use Xivi\Core\Metadata\ConversionPlan;
use Xivi\Core\Metadata\FieldTypeConversion;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Metadata\NumberingChange;
use Xivi\Core\Module\ModuleUpgrade;
use Xivi\Core\Numbering\AssignsNumbers;
use Xivi\Core\Numbering\CounterRefused;
use Xivi\Core\Numbering\NumberFormat;
use Xivi\Core\Period\ExclusiveWithin;
use Xivi\Core\Phone\PhoneRegion;
use Xivi\Core\ValueList\ValueLists;

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
 * **Three doors rather than one page** ([XIV-163]). Until this ticket the whole
 * editor was a single table with every control on it: every field of every
 * shape, every setting of every type, the order, the widths and the add form,
 * all at once. Two things were wrong with that and only one of them was
 * cosmetic. The cosmetic one is that the commonest act by far, adding one field,
 * happens on a page mostly occupied by the fields somebody already has. The
 * other is the shape XIV-144 fought and lost ground to again: **one form has to
 * carry every type's options at once**, so it draws a country beside a date and
 * a maximum length beside a reference, and the only defence against that is
 * `{% if %}`s nobody can read in a template nobody wants to open. A form that
 * offers what it cannot configure is the defect; a form that offers what the
 * field cannot use is the same defect wearing a hat.
 *
 * So the page a customer lands on offers three, and each of them asks one
 * question:
 *
 *  * **add a field**, which asks the *type* first, from a list built out of the
 *    registry so that a type registered tomorrow appears with no change here,
 *    and then draws a form of that type's own options and nothing else;
 *  * **edit a field**, which lists the shape's fields and then draws, again,
 *    only what that field's type declares;
 *  * **arrange the form**, which is where the cross-field concerns live: the
 *    order, the widths, which heading each field sits under and which fields the
 *    list shows. Those are facts about the form as a whole rather than about any
 *    one field, which is why they were the part of the old table that actually
 *    worked.
 *
 * **The old combined form is gone rather than kept beside this**, and no route
 * renders it. Two editors for one thing drift apart with every option added
 * after today, and the drift is silent: both pages keep working, one of them
 * quietly stops offering something. That is the same argument that produced
 * {@see self::PER_TYPE}, applied one level up.
 *
 * **The refusals did not move and must not.** Every rule §5.4 lists is enforced
 * by {@see MetadataEditor}, on the write path, where the console and the
 * importer meet it too: no type change, no key change, a rule counted against
 * existing records before it is applied, a module's own fields protected, an
 * option a record holds, a populated reference's target. What changed here
 * is which page asks the question. A refusal that moved into a controller would
 * be a refusal the importer no longer has.
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
     * The settings whose control depends on the field's *type*, and what
     * declares each (XIV-36, XIV-27).
     *
     * A `text` field has nothing to autocomplete against and a `date` cannot be
     * a document number, and a control that does nothing is worse than an absent
     * one: somebody fills it in and waits for something to happen.
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
        // The sixth, and the first that is a **second answer to a question
        // already on this list** rather than a question of its own (XIV-127). A
        // `choice` field's values may come from its own options or from a list
        // the customer keeps beside it, and `needs()` says so by naming both in
        // one entry — so this line is what makes the second one drawable, and
        // `configurable()` below is what refuses a type that declared it and
        // could not be asked.
        ChoiceFieldType::LIST => PointsAtAList::class,
        // The seventh (XIV-136), and back to being an option with a good empty
        // answer: what a period is exclusive within. Blank means the periods in
        // this field may overlap each other freely, which is what a project's
        // duration wants and what a booking's does not. One line here, one
        // capability interface and one `<select>` of this shape's own fields —
        // and, for the first time on this list, an option whose control has to
        // know which *shape* it is being drawn on, since the answer is a field
        // beside it rather than a value from a fixed list.
        ExclusiveWithin::OPTION => ExcludesOverlaps::class,
        // The eighth and ninth, and the first two that were already here
        // ([XIV-163]). `max_length`, `min` and `max` used to live in a second
        // list of this class's own and were drawn for every field there is, on
        // the argument that a number is harmless where it means nothing. Giving
        // each type a form of its own is what made that untenable: "the options
        // this type declares" is the whole content of such a form, so a setting
        // outside the declarations would have to be drawn on every one of them
        // or on none. Promoting them cost what the third one cost, twice: an
        // interface, a line here and a control in a template.
        //
        // Note that `min` and `max` are two options keyed to one capability,
        // which this list could always express and had never needed to. A type
        // that can be bounded below can be bounded above, so two interfaces
        // would be two interfaces always declared together.
        LimitsItsLength::OPTION => LimitsItsLength::class,
        BoundsItsValues::MIN => BoundsItsValues::class,
        BoundsItsValues::MAX => BoundsItsValues::class,
    ];

    /**
     * The options whose control is a page rather than a box on a form
     * (XIV-27, XIV-144).
     *
     * Two of the declarations above name something too big to be a row in a
     * form: a numbering pattern needs the number it would produce shown beside
     * it as somebody types, and a choice field's list of options is a row each,
     * with a rename that must not move a record and a removal that may be
     * refused with a paragraph. Both already had their page before [XIV-163],
     * and both keep it.
     *
     * So the per-field form draws neither and links to both, which is what this
     * list is for. **The add form is the exception, and only for the options**:
     * a page belonging to a field cannot be reached before the field exists, and
     * the engine refuses to write a `choice` field with nothing to choose
     * between, so the one place that must ask for the list up front is the one
     * where the field is being made. Numbering has no such problem, because a
     * field with no pattern is an ordinary text field and a perfectly good thing
     * to have just made.
     *
     * @var list<string>
     */
    private const array OWN_PAGE = [NumberFormat::OPTION, ChoiceFieldType::CHOICES];

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly MetadataEditor $editor,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly TranslatorInterface $translator,
        private readonly NumberingChange $numbering,
        // Changing a field's type on a tenant that already has records
        // ([XIV-146]). Beside the numbering change and for the same reason: it
        // is a plan and a run, the plan is what the confirmation page is drawn
        // from, and neither half belongs in a controller.
        private readonly FieldTypeConversion $conversion,
        private readonly ModuleUpgrade $upgrade,
        // The shared lists a choice field may take its values from (XIV-127).
        // Read-only here: what a list *is* is edited on its own screens
        // ({@see ValueListController}), and this page only ever offers one.
        private readonly ValueLists $lists,
    ) {
    }

    /**
     * The three doors, one set per shape ([XIV-163]).
     *
     * What used to be the whole editor is now a page that asks which of three
     * things somebody came to do, and the argument for each of them is on the
     * class above. The only thing decided *here* is that the choice is offered
     * per shape rather than once: a module and its collections are separate
     * shapes with separate fields (§5.1), so "add a field" has to know which one
     * before it can ask anything else, and asking that on a fourth page would be
     * a page whose only content is a question somebody has already answered by
     * looking at the module they are in.
     *
     * The shape's own name stays editable here, because it is the one thing
     * about a shape that is not about its fields at all (XIV-8) and there is
     * nowhere else it belongs.
     */
    #[Route('', name: 'field_index', methods: ['GET'])]
    public function index(string $module): Response
    {
        $definition = $this->definition($module);

        return $this->render('field/index.html.twig', [
            'module' => $definition,
            'shapes' => self::shapesOf($definition),
            // Which fields are drawn, saved, validated and useless: a choice
            // field with no options, a reference with nothing to point at
            // (XIV-144). Nothing new can reach this state — the engine refuses
            // it — so what this marks is a field that predates the rule or one a
            // module wrote itself, and it is marked rather than left because
            // §8.3.1's whole argument is that silence is the worse failure. A
            // count on the door here, and the fields themselves behind it.
            'unfinished' => $this->unfinishedIn($definition),
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
     * The first door: which kind of field, asked before anything else
     * ([XIV-163]).
     *
     * **The type is the question every other question depends on**, which is why
     * it is asked on a page of its own rather than in a select in the middle of
     * a form. What a `choice` field needs and what a `phone` field offers have
     * nothing in common, so a single form asking for a key, a label, a type and
     * then every option any type might want is a form that is mostly wrong
     * whatever gets chosen. Asked first, the type decides what the next page
     * contains.
     *
     * Built from the registry through {@see self::addableTypes()}, so a type
     * registered tomorrow is offered here with nothing changed in this class,
     * and a type whose questions this editor cannot ask is not offered at all,
     * which is XIV-144's rule and is now the thing deciding what this page
     * lists.
     */
    #[Route('/{shape}/add', name: 'field_types', requirements: ['shape' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function types(string $module, int $shape): Response
    {
        $definition = $this->definition($module);
        $target = $this->shape($definition, $shape);
        $addable = $this->addableTypes($target);

        return $this->render('field/types.html.twig', [
            'module' => $definition,
            'shape' => $target,
            'addable' => $addable,
            // What each type will be asked about, so that the choice is made
            // knowing it. Through {@see self::drawnFor()} rather than through
            // the declarations directly, so this is the next page's contents
            // exactly: a hint promising a control that page does not draw would
            // be worse than no hint, and naming numbering here would promise one
            // that is deliberately somewhere else.
            'settings' => array_map(
                static fn (FieldType $type): array => self::drawnFor($type, $target, adding: true),
                $addable,
            ),
        ]);
    }

    /**
     * The second half of the first door: this type's own options, and no others.
     *
     * Everything on this form is either true of every field there is, the key,
     * the label and the rules, or declared by the type being added
     * ({@see self::drawnFor()}). Nothing else is drawn, which makes XIV-26's rule
     * that a form does not touch what it does not mention cost nothing to keep:
     * the form mentions exactly what the type says it has.
     *
     * **A type's {@see NeedsAnAnswer} questions are asked here**, and that is
     * the difference this ticket makes rather than a detail of layout. The
     * engine refuses a `choice` field with nothing to choose between and a
     * `reference` pointing nowhere, on the write path where the console and the
     * importer meet the same rule (§5.4). Before [XIV-163] the only place that
     * asked was a pair of boxes at the bottom of the combined form, drawn for
     * every add whatever type was chosen and labelled with the type they were
     * for, so the ordinary way to meet those refusals was to submit and be
     * told. Asking on the form for the type that needs them does not weaken the
     * refusal by one line; it stops the refusal being the first anybody hears of
     * the requirement.
     */
    #[Route('/{shape}/add/{type}', name: 'field_add_form', requirements: ['shape' => Requirement::POSITIVE_INT, 'type' => '[a-z][a-z0-9_]*'], methods: ['GET'])]
    public function addForm(string $module, int $shape, string $type, Request $request): Response
    {
        $definition = $this->definition($module);
        $target = $this->shape($definition, $shape);
        $addable = $this->addableTypes($target);

        if (!isset($addable[$type])) {
            // Either a type that does not exist or one whose questions this
            // editor cannot ask, and both deserve the same answer: the list this
            // page is reached from does not contain it, so arriving here means a
            // typed URL or a link that has gone stale. Back to that list rather
            // than a 404, because the list is what says which answers are honest.
            $this->addFlash('warning', $this->translator->trans('flash.type_not_addable', ['%type%' => $type]));

            return $this->redirectToRoute('field_types', ['module' => $module, 'shape' => $shape]);
        }

        return $this->render('field/add.html.twig', [
            'module' => $definition,
            'shape' => $target,
            'type' => $type,
            'fieldType' => $addable[$type],
            ...$this->controlsFor($addable[$type], $target, adding: true, request: $request),
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
                    // Which heading it is under, handed back as it already is
                    // (XIV-119): this page draws no control for it, and a page
                    // that does not draw a setting must not decide it.
                    section: $target->getSection(),
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
     * Changing this field's type, on a page of its own ([XIV-146], §7.2).
     *
     * **The fourth page to earn one, on the argument the other three made.**
     * §5.4 has now said three times that a change which writes into records
     * already there is a paragraph and a confirmation rather than a control on a
     * row: numbering's backfill, a choice field's options, removing a field. A
     * type change is the largest of the four. It rewrites every value in a
     * column, it can be refused by the customer's own data, and half the
     * conversions worth doing cannot be undone. A `<select>` beside the label
     * would make it look like relabelling.
     *
     * This first page only asks which type, and asks nothing else, which is
     * [XIV-163]'s shape read one door along: the type is the question every other
     * question depends on, so it is asked alone and the next page is built from
     * the answer.
     *
     * The list is {@see self::convertibleTypes()}, so a type registered tomorrow
     * is offered here with nothing changed in this class.
     */
    #[Route('/{field}/type', name: 'field_type', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function fieldType(string $module, int $field): Response
    {
        $definition = $this->definition($module);
        $target = $this->field($definition, $field);

        if ($target->isDerived()) {
            // The engine fills it, so its type is not the customer's to restate
            // (§5.9). Refused on the write path too; this is the page simply not
            // opening, with the sentence that write path would have used.
            $this->addFlash('warning', MetadataChangeRefused::typeOfADerivedField($target->getKey())
                ->translatable()
                ->trans($this->translator));

            return $this->redirectToRoute('field_edit', ['module' => $module, 'field' => $field]);
        }

        return $this->render('field/convert_types.html.twig', [
            'module' => $definition,
            'shape' => $target->getShape(),
            'field' => $target,
            'fieldType' => $this->fieldTypes->has($target->getType()) ? $this->fieldTypes->get($target->getType()) : null,
            'convertible' => $this->convertibleTypes($target),
            'held' => $this->editor->recordsHolding($target),
        ]);
    }

    /**
     * What the change would do, before anything is written ([XIV-146]).
     *
     * The dry run §7.2 asks for, and it is the same computation the real run
     * makes: every value in the column read by the type it is moving to, counted
     * rather than estimated. What comes back says how many rows convert, what
     * they become, how many the new type cannot read and what those say, whether
     * a `unique` promise would be broken by the result, and whether converting
     * straight back would give every record what it holds today.
     *
     * A POST rather than a GET, on numbering's terms: this scans a customer's
     * whole records table, and a link that does that is a link somebody can put
     * in a crawler.
     */
    #[Route('/{field}/type/{type}', name: 'field_type_check', requirements: ['field' => Requirement::POSITIVE_INT, 'type' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    public function checkTypeChange(string $module, int $field, string $type, Request $request): Response
    {
        $definition = $this->definition($module);
        $target = $this->field($definition, $field);
        $convertible = $this->convertibleTypes($target);

        if (!$this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))
            || !isset($convertible[$type])
        ) {
            // Either a type this field cannot be converted to or a form posted
            // around the page that says which ones it can. Back to that page,
            // because it is the thing that says which answers are honest.
            $this->addFlash('warning', $this->translator->trans('flash.type_not_convertible', ['%type%' => $type]));

            return $this->redirectToRoute('field_type', ['module' => $module, 'field' => $field]);
        }

        return $this->render('field/convert_check.html.twig', [
            'module' => $definition,
            'shape' => $target->getShape(),
            'field' => $target,
            'type' => $type,
            'fieldType' => $convertible[$type],
            'plan' => $this->conversion->plan($target, $type),
            'shown' => ConversionPlan::VALUES_NAMED,
        ]);
    }

    /**
     * And doing it, once somebody has said the word ([XIV-146]).
     *
     * The confirmation is required here rather than only in the template, for
     * the reason the numbering backfill gives: a `required` attribute is a
     * courtesy to somebody using the page and nothing at all to a form posted
     * around it, and on the other side of this call is a rewrite of every value
     * in a column that half the time cannot be undone.
     *
     * **`empty` is read as a separate word from `confirm`, and that is §7.2's
     * rule rather than a form detail.** Emptying the rows the new type cannot
     * read is the customer's second choice, taken with the report in front of
     * them; it is a box of its own, unticked, and there is no path through this
     * controller that reaches the engine with it true unless somebody ticked it.
     *
     * Everything else is {@see FieldTypeConversion::convert()}'s, in one
     * transaction: a refusal, a browser closing or a column too large to finish
     * leaves every value spelled the way it was spelled this morning.
     */
    #[Route('/{field}/type/{type}/apply', name: 'field_type_convert', requirements: ['field' => Requirement::POSITIVE_INT, 'type' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    public function convertField(string $module, int $field, string $type, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))
            && $request->request->getBoolean('confirm')
        ) {
            $target = $this->field($definition, $field);

            try {
                $done = $this->conversion->convert($target, $type, $request->request->getBoolean('empty'));

                // The figures come back from the run rather than from the page
                // that was agreed to: a record saved between the two is one more
                // record converted, and the sentence somebody reads afterwards
                // should be about what happened.
                $this->addFlash('success', $this->translator->trans('flash.field_converted', [
                    '%field%' => $target->getLabel(),
                    '%count%' => $done->converts,
                    '%emptied%' => $done->refuses,
                ]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));

                return $this->redirectToRoute('field_type', ['module' => $module, 'field' => $field]);
            }
        }

        return $this->redirectToRoute('field_edit', ['module' => $module, 'field' => $field]);
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
            // The shared list this field takes its values from, if it does
            // (XIV-127) — in which case the options below are the field's *own*
            // and are dormant.
            //
            // **This page stays reachable for such a field, deliberately.** A
            // 404 was the tidier answer and it is a dead end: taking a field back
            // off a list is only allowed when its own options cover what records
            // hold, so somebody who wants to leave has to be able to edit those
            // options first, and this is the only page that edits them. The
            // banner says which of the two lists is actually in use, because a
            // page of options that do nothing, unlabelled, is §8.3.1's failure.
            'takesFrom' => ChoiceFieldType::listKeyOf($target) === ''
                ? null
                : $this->lists->find(ChoiceFieldType::listKeyOf($target)),
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
                    // Which heading it is under, handed back as it already is
                    // (XIV-119): this page draws no control for it, and a page
                    // that does not draw a setting must not decide it.
                    section: $target->getSection(),
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

    /**
     * The write behind the first door.
     *
     * The shape and the type are in the URL rather than in hidden inputs, which
     * is not tidiness: they are what decided which controls this form has, so a
     * post that disagreed with the page it came from would be a post nobody
     * could reason about. `UnknownFieldType` is still caught, because the URL is
     * as forgeable as a select was.
     *
     * **A refusal goes back to the form for this type**, not to a list. Every
     * message the engine produces here names something to change, a key that is
     * not an identifier or a choice field with nothing to choose between, and
     * the page to change it on is the one that asked.
     */
    #[Route('/{shape}/add/{type}', name: 'field_add', requirements: ['shape' => Requirement::POSITIVE_INT, 'type' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    public function add(string $module, int $shape, string $type, Request $request): Response
    {
        $definition = $this->definition($module);
        $target = $this->shape($definition, $shape);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            try {
                $field = $this->editor->addField(
                    shape: $target,
                    key: (string) $request->request->get('key'),
                    label: (string) $request->request->get('label'),
                    type: $type,
                    required: $request->request->getBoolean('required'),
                    unique: $request->request->getBoolean('unique'),
                    filterable: $request->request->getBoolean('filterable'),
                    // `listed` is not here and not on the form, because since
                    // [XIV-163] it belongs to the third door with the rest of
                    // what the *list* and the *form* look like. The engine's
                    // default is off, which is the answer XIV-26 argued for
                    // anyway: a field added today should not widen a list
                    // somebody reads every day until somebody says so.
                    title: $request->request->getBoolean('title'),
                    options: [
                        ...$this->optionsFrom($request, $type),
                        // The list a choice field is added with (XIV-144). Here
                        // and not in optionsFrom() because this is the one form
                        // that draws it; the per-field form links to the options
                        // page instead, and a form that does not draw a setting
                        // does not name it.
                        ...$this->choicesFrom($request, $type),
                    ],
                );

                $this->addFlash('success', $this->translator->trans('flash.field_added', ['%field%' => $field->getLabel()]));

                return $this->redirectToRoute('field_list', ['module' => $module, 'shape' => $shape]);
                // UnknownFieldType too: the URL is built from the registry, so a
                // type it does not know means a typed or stale one, which is a
                // message rather than a stack trace.
            } catch (MetadataChangeRefused|UnknownFieldType $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_add_form', ['module' => $module, 'shape' => $shape, 'type' => $type]);
    }

    /**
     * The second door: the shape's fields, as a list to choose one from.
     *
     * A table of names and types with nothing to fill in, which is the whole
     * point of splitting it from the form. What is on a row is what tells
     * somebody which row they want: the key a value is stored under, the label
     * they read, the type, whether the field is unfinished, and the ways on to
     * the pages that belong to this field rather than to its type: its options,
     * its numbering, and removing it.
     */
    #[Route('/{shape}/edit', name: 'field_list', requirements: ['shape' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function fields(string $module, int $shape): Response
    {
        $definition = $this->definition($module);

        return $this->render('field/list.html.twig', [
            'module' => $definition,
            'shape' => $this->shape($definition, $shape),
            // Every registered type, because this is what names a field's type
            // in the table, and a field of a type this editor cannot configure
            // still has to say what it is.
            'types' => $this->fieldTypes->all(),
            // Which of them are of a type that cannot work until something is
            // set, with it unset (XIV-144). A badge on the row, because this is
            // the page somebody scans looking for what needs attention; the
            // sentence about it is on the field's own form.
            'unfinished' => $this->unfinishedIn($definition),
        ]);
    }

    /**
     * One field's form: what is true of every field, and what its type declares.
     *
     * The same rule as the add form and for the same reason
     * ({@see self::drawnFor()}), with two differences that come from the field
     * already existing. Its **key** and its **type** are drawn as text rather
     * than as controls, because §5.4 refuses to change either and a disabled
     * control is a worse way of saying so than a sentence. And the two options
     * that have pages of their own are links here rather than boxes: the field
     * exists, so its options page and its numbering page can be reached.
     */
    #[Route('/{field}', name: 'field_edit', requirements: ['field' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function edit(string $module, int $field, Request $request): Response
    {
        $definition = $this->definition($module);
        $target = $this->field($definition, $field);
        $shape = $target->getShape();
        // A field whose type nothing registers any more, because a module was
        // removed or a type renamed. The page still opens, because the label
        // and the rules
        // are still editable and the alternative is a customer who cannot get at
        // their own definition; what it cannot draw is any per-type control,
        // which drawnFor() answers with an empty list rather than a branch here.
        $type = $this->fieldTypes->has($target->getType()) ? $this->fieldTypes->get($target->getType()) : null;

        return $this->render('field/edit.html.twig', [
            'module' => $definition,
            'shape' => $shape,
            'field' => $target,
            'fieldType' => $type,
            'unfinished' => $this->unfinishedIn($definition),
            'numbered' => $this->numberedIn($definition),
            'numberable' => $this->numberableIn($definition),
            'settable' => $this->settableByType(),
            ...$this->controlsFor($type, $shape, adding: false, request: $request),
        ]);
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
                    // The three the third door owns, handed back exactly as they
                    // are ([XIV-163]). {@see MetadataEditor::updateField()} takes
                    // the value a field ends up with rather than a change, so a
                    // page that draws no control for something has to say what it
                    // already was. Otherwise "the form does not mention it"
                    // would mean "the form clears it", which is XIV-26's accident
                    // in the one place that rule was written for.
                    listed: $target->isListed(),
                    title: $request->request->getBoolean('title'),
                    position: $request->request->getInt('position', $target->getPosition()),
                    options: $this->optionsFrom($request, $target->getType()),
                    width: $target->getWidth(),
                    section: $target->getSection(),
                );

                $this->addFlash('success', $this->translator->trans('flash.field_saved', ['%field%' => $target->getLabel()]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_edit', ['module' => $module, 'field' => $field]);
    }

    /**
     * The third door: the four things that are about the form rather than about
     * a field ([XIV-163]).
     *
     * Order, width, which heading a field sits under and whether the list shows
     * it. Every one of them is decided by looking at the *other* fields: a
     * position means nothing except relative to the rest, two half-width fields
     * share a row, a section is a run of them, and a list column is worth having
     * only against the columns already there. The old table put these beside the
     * per-type settings and they were the part of it that worked, because they
     * are the part that genuinely wants every field visible at once.
     *
     * Section management is reached from here rather than from the index
     * (XIV-119), which is the same argument one step down: a section is made on
     * its own page, and the page that needs it is this one.
     */
    #[Route('/{shape}/arrange', name: 'field_arrange', requirements: ['shape' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function arrange(string $module, int $shape): Response
    {
        $definition = $this->definition($module);
        $target = $this->shape($definition, $shape);

        return $this->render('field/arrange.html.twig', [
            'module' => $definition,
            'shape' => $target,
            'types' => $this->fieldTypes->all(),
            // The headings this customer has made, for the select that says
            // which of them a field is drawn under (XIV-119). Empty on a
            // collection, whose rows are a table and where a heading in the
            // middle of a table row is nothing at all. The template draws no
            // column there rather than an empty select.
            'sections' => $target instanceof ModuleDefinition ? $target->getSections() : [],
        ]);
    }

    /**
     * Every field of the shape, saved at once, because the decision was made
     * across all of them.
     *
     * **One `updateField()` per field rather than a bulk write**, which is not
     * an oversight about round trips. Every refusal §5.4 lists lives in that
     * method, and a second path into the definitions that skipped them would be
     * exactly the migration this ticket forbade: the doors are presentation, the
     * enforcement is the engine's. What this page sends is the four settings it
     * draws; everything else about each field is handed back as it already is,
     * for the reason {@see self::update()} gives.
     *
     * A refusal is per field and does not stop the rest. Each save is its own
     * transaction already, so pretending the page is one would mean claiming
     * something the storage does not do; and the only refusal reachable from
     * here at all is a section key that was never on the page, which is a posted
     * form rather than somebody's afternoon.
     */
    #[Route('/{shape}/arrange', name: 'field_arrange_save', requirements: ['shape' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function saveArrangement(string $module, int $shape, Request $request): Response
    {
        $definition = $this->definition($module);
        $target = $this->shape($definition, $shape);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            /** @var array<string, mixed> $positions */
            $positions = $request->request->all('position');
            /** @var array<string, mixed> $widths */
            $widths = $request->request->all('width');
            /** @var array<string, mixed> $sections */
            $sections = $request->request->all('section');
            /** @var array<string, mixed> $listed */
            $listed = $request->request->all('listed');
            $refused = false;

            foreach ($target->getFields() as $field) {
                $id = (string) $field->getId();

                try {
                    $this->editor->updateField(
                        field: $field,
                        label: $field->getLabel(),
                        required: $field->isRequired(),
                        unique: $field->isUnique(),
                        filterable: $field->isFilterable(),
                        // A checkbox sends nothing when it is unticked, so the
                        // absence of a field's id here is somebody turning the
                        // column off. That reading is safe only because this
                        // form draws a checkbox for every field of the shape,
                        // which is the whole difference between it and the
                        // per-field form beside it.
                        listed: \array_key_exists($id, $listed),
                        title: $field->isTitle(),
                        position: isset($positions[$id]) ? (int) $positions[$id] : $field->getPosition(),
                        // Nothing about the options, so nothing of the options
                        // changes and none of their guards has anything to judge
                        // (XIV-26).
                        options: [],
                        width: self::widthOf((string) ($widths[$id] ?? '')),
                        // Absent and empty are read the same way here, unlike on
                        // the reference target: this form draws the select for
                        // every field it lists, so an id that is missing is a
                        // browser that sent an empty select, and empty means "in
                        // no section", which is a real answer and the common
                        // one. A collection draws no select at all, and the
                        // fields of a collection have never had a section to
                        // lose.
                        section: self::sectionOf((string) ($sections[$id] ?? '')),
                    );
                } catch (MetadataChangeRefused $e) {
                    $this->addFlash('warning', $e->translatable()->trans($this->translator));
                    $refused = true;
                }
            }

            if (!$refused) {
                $this->addFlash('success', $this->translator->trans('flash.fields_arranged'));
            }
        }

        return $this->redirectToRoute('field_arrange', ['module' => $module, 'shape' => $shape]);
    }

    /**
     * The width a form sent, or null for "follow the field type" (XIV-43).
     *
     * An empty box is the default rather than a zero: the control offers a blank
     * option and that blank is what almost every field should keep. Anything
     * outside 1-12 is nonsense from a hand-edited form and is treated as blank
     * rather than clamped, because a form that quietly turns 40 into 12 tells
     * somebody they got what they asked for.
     *
     * Takes the value rather than the request since [XIV-163], because the page
     * that draws this control now draws one per field and sends them as an array
     * keyed by field id. The rule about what a width may be is the same rule
     * wherever it is read from.
     */
    private static function widthOf(string $width): ?int
    {
        $width = trim($width);

        if ($width === '' || !ctype_digit($width)) {
            return null;
        }

        $width = (int) $width;

        return $width >= 1 && $width <= 12 ? $width : null;
    }

    /**
     * Which heading a field is drawn under, as the form sent it (XIV-119).
     *
     * Blank means "in no section", which is a real answer and the common one: a
     * field in no section is drawn at the top of the form, exactly where every
     * field in this product was drawn before sections existed.
     *
     * Nothing is checked against the module's own list here. That check is on
     * the write path, where the console and an import meet it too — and unlike
     * the width above it is a refusal rather than a shrug, for the reason
     * {@see MetadataChangeRefused::unknownSection()} gives.
     */
    private static function sectionOf(string $section): ?string
    {
        $section = trim($section);

        return $section === '' ? null : $section;
    }

    /**
     * The headings on a module's form: making them, naming them, ordering them
     * (XIV-119).
     *
     * **A page rather than a column in the field table**, and it is the third
     * time §5.4 has reached for that argument — numbering and a choice field's
     * options were the first two. Everything in that table is a control per
     * field: a label, a checkbox, a width. A section is not a fact about a
     * field, it is a thing of its own with a name and a place, and there has to
     * be somewhere to *make* one before any field can be put in it. The field
     * table's job here is one select per row saying which heading that field is
     * under, which is instantaneous and reversible and therefore fits a cell.
     *
     * The count beside each one is what makes the delete link legible before it
     * is followed, on the options page's principle: a number somebody reads
     * while planning beats the same number in a sentence after they tried.
     */
    #[Route('/sections', name: 'field_sections', methods: ['GET'])]
    public function sections(string $module): Response
    {
        $definition = $this->definition($module);

        return $this->render('field/sections.html.twig', [
            'module' => $definition,
            'sections' => $definition->getSections(),
            'counts' => $this->fieldsPerSection($definition),
        ]);
    }

    /**
     * Renaming and reordering, in one save, because they are one list.
     *
     * **A section missing from what arrives is left where it is**, which is the
     * opposite contract to a choice field's options and is deliberate: there,
     * absence had to mean removal or a removal could never be expressed at all;
     * here removal is its own page with a sentence about the fields, so absence
     * can safely mean "not mentioned".
     */
    #[Route('/sections', name: 'field_sections_save', methods: ['POST'])]
    public function saveSections(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            /** @var array<string, mixed> $labels */
            $labels = $request->request->all('label');
            /** @var array<string, mixed> $positions */
            $positions = $request->request->all('position');

            $this->editor->updateSections(
                $definition,
                array_map(static fn (mixed $label): string => trim((string) $label), $labels),
                self::positionsFrom($positions),
            );

            $this->addFlash('success', $this->translator->trans('flash.sections_saved'));
        }

        return $this->redirectToRoute('field_sections', ['module' => $module]);
    }

    /**
     * Making one, on a form of its own beside the list.
     *
     * **Its own form rather than a box on the save above**, and the deciding
     * argument is what an empty one has to mean. On a combined form, an untouched
     * "add" box is the ordinary state of every save that only renames something,
     * so blank would have to mean "nothing to add" — and the engine's refusal of
     * a nameless section would then be a rule no page could ever reach, which is
     * the sort of protection that is discovered to be broken years later. Here
     * blank means somebody pressed Add with nothing typed, and gets the sentence.
     *
     * The field table below has exactly this arrangement for exactly this
     * reason: a row per thing, and one form under it that makes another.
     */
    #[Route('/sections/add', name: 'field_section_add', methods: ['POST'])]
    public function addSection(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            try {
                $section = $this->editor->addSection($definition, (string) $request->request->get('name'));

                $this->addFlash('success', $this->translator->trans('flash.section_added', [
                    '%section%' => $section->label,
                ]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_sections', ['module' => $module]);
    }

    /**
     * What deleting a heading does, said before it happens.
     *
     * The one thing somebody would otherwise assume wrongly, and they would
     * assume it in the direction that costs them: a section looks like a
     * container, so deleting one looks like deleting what is inside it. It is
     * not — the fields keep their values, their order, their widths and their
     * rules, and go back to being drawn at the top of the form, exactly as every
     * field in this product was drawn before this feature existed. The
     * confirmation says the number as well as the sentence, because "3 fields
     * come back to the top" is a different decision from "31 do".
     */
    #[Route('/sections/{section}/delete', name: 'field_section_confirm_delete', requirements: ['section' => '[a-z][a-z0-9_]*'], methods: ['GET'])]
    public function confirmDeleteSection(string $module, string $section): Response
    {
        $definition = $this->definition($module);

        if (!$definition->hasSection($section)) {
            throw $this->createNotFoundException(sprintf('No section "%s" on "%s".', $section, $module));
        }

        return $this->render('field/section_delete.html.twig', [
            'module' => $definition,
            'section' => $definition->getSection($section),
            'holding' => $this->editor->fieldsIn($definition, $section),
        ]);
    }

    #[Route('/sections/{section}/delete', name: 'field_section_delete', requirements: ['section' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    public function deleteSection(string $module, string $section, Request $request): Response
    {
        $definition = $this->definition($module);

        if ($this->isCsrfTokenValid('edit-fields', (string) $request->request->get('_token'))) {
            try {
                // Read before it is gone, because the flash names it: an
                // unknown key falls back to the key itself, and is then refused
                // on the next line with a sentence of its own.
                $existing = $definition->getSection($section);
                $label = $existing === null ? $section : $existing->label;
                $this->editor->removeSection($definition, $section);

                $this->addFlash('success', $this->translator->trans('flash.section_removed', [
                    '%section%' => $label,
                ]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));
            }
        }

        return $this->redirectToRoute('field_sections', ['module' => $module]);
    }

    /**
     * The positions the sections page sent, as numbers.
     *
     * Anything that is not digits is a number control edited by hand, and is
     * dropped rather than clamped — the width's rule, for the width's reason: a
     * form that quietly turns nonsense into a value tells somebody they got what
     * they asked for. A dropped key means that section keeps the place it had.
     *
     * @param array<string, mixed> $positions
     *
     * @return array<string, int>
     */
    private static function positionsFrom(array $positions): array
    {
        $clean = [];

        foreach ($positions as $key => $value) {
            $value = trim((string) $value);

            if ($value !== '' && ctype_digit($value)) {
                $clean[(string) $key] = (int) $value;
            }
        }

        return $clean;
    }

    /**
     * How many fields sit under each heading, keyed by section (XIV-119).
     *
     * Counted off the definition that is already in hand rather than queried:
     * this is a fact about the module's own fields, which the metadata cache has
     * already loaded whole (XIV-53).
     *
     * @return array<string, int>
     */
    private function fieldsPerSection(ModuleDefinition $module): array
    {
        $counts = [];

        foreach ($module->getSections() as $section) {
            $counts[$section->key] = $this->editor->fieldsIn($module, $section->key);
        }

        return $counts;
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

            $shape = $target->getShape();

            try {
                $this->editor->removeField($target);
                $this->addFlash('success', $this->translator->trans('flash.field_removed', ['%field%' => $target->getLabel()]));
            } catch (MetadataChangeRefused $e) {
                $this->addFlash('warning', $e->translatable()->trans($this->translator));

                return $this->redirectToRoute('field_edit', ['module' => $module, 'field' => $field]);
            }

            // Back to the list this field was chosen from, which is the page a
            // removal leaves somebody wanting to look at. Its own form is gone
            // with it, so there is nowhere else to go. A refusal has somewhere
            // very specific, which is the field that is still there.
            return $this->redirectToRoute('field_list', ['module' => $module, 'shape' => $shape->getId()]);
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
     * The per-type settings the form drew, and only those (XIV-26).
     *
     * **Every one of them is named on every save, cleared ones as null**, and
     * nothing else is named at all. That is the contract MetadataEditor merges
     * on: what the form does not mention it does not touch, so a field's
     * `choices`, what it inherits and how it is numbered all survive an edit
     * this form has no idea it is next to.
     *
     * Blank means gone rather than absent, so a limit somebody has emptied out
     * really is emptied — a form that could only ever add a setting would be the
     * opposite bug.
     *
     * **Every setting here is asked of the type first**, which since [XIV-163]
     * has no exceptions. Three of them used to be named unconditionally, so a
     * date field's save carried a `min`, a `max` and a `max_length`, all null,
     * all clearing nothing, and all of them a control that would have been drawn
     * beside a field it means nothing on if anybody had drawn them. They are
     * declared like everything else now ({@see LimitsItsLength},
     * {@see BoundsItsValues}), so a type that does not offer one is not asked
     * about it and cannot have one cleared.
     *
     * Reading a control is deliberately per option and always has been: a select
     * of three fixed answers, a country checked against symfony/intl and a
     * number that may be blank have nothing in common but the question of which
     * types may have one. That question is {@see self::PER_TYPE}'s; this is the
     * answer to "and what did the box say".
     *
     * @return array<string, int|string|null>
     */
    private function optionsFrom(Request $request, string $type): array
    {
        $options = [];

        foreach ([LimitsItsLength::OPTION, BoundsItsValues::MIN, BoundsItsValues::MAX] as $option) {
            if (!$this->offers($option, $type)) {
                continue;
            }

            // A whole number or nothing. Anything else is a hand-edited form
            // and reads as "no limit", on the same terms as every other control
            // here: the honest response to a value the page could not have
            // produced is to change nothing rather than to store a nonsense the
            // type will later have to defend itself against.
            //
            // Whole numbers only, which is a limitation and an old one: a
            // module's blueprint may give a currency field a bound of 0.5 and
            // the type reads it as a float, but this control has cast to int
            // since it was written and nothing has ever wanted otherwise. What
            // changed with [XIV-163] is only that a value the box cannot produce
            // is now left alone instead of being rounded silently.
            $value = trim((string) $request->request->get($option, ''));
            $options[$option] = preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
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

        if ($this->offers(ExclusiveWithin::OPTION, $type)) {
            // Blank is a real answer and clears, like the width and the country
            // above: a period field that is exclusive within nothing is the
            // ordinary kind. What the select offers is this shape's own other
            // fields, and what it is checked against is nothing at all here —
            // the engine refuses a key that is not a field, on the write path
            // where a console command and an import meet the same rule, and a
            // second copy of that check in a controller is the copy that gets
            // forgotten (XIV-136).
            $scope = trim((string) $request->request->get(ExclusiveWithin::OPTION, ''));
            $options[ExclusiveWithin::OPTION] = $scope === '' ? null : $scope;
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

        if ($this->offers(ChoiceFieldType::LIST, $type) && $request->request->has(ChoiceFieldType::LIST)) {
            // Which shared list a choice field takes its values from, or none
            // (XIV-127) — and this one **does** have a meaningful empty, unlike
            // the reference target above.
            //
            // Blank here is not "a field that does nothing", it is a field
            // keeping its own options, which is what every `choice` field in
            // every tenant already does and will carry on doing without anybody
            // touching this control. So blank goes through as null, the merge
            // clears the option, and the engine then judges whether the field is
            // left with anything to be a choice between — which for a field that
            // never had its own options is a refusal, and for one that did is
            // the way back.
            //
            // Guarded by `has()` for the same reason the target is: a form that
            // did not draw the control must not be read as somebody emptying it.
            // The row form draws it for every choice field; the numbering and
            // options pages draw neither and say nothing.
            //
            // Not checked against the lists that exist here — that check is on
            // the write path, where the console and the importer meet it too,
            // and a second copy in a controller is the copy that gets forgotten.
            $list = trim((string) $request->request->get(ChoiceFieldType::LIST, ''));
            $options[ChoiceFieldType::LIST] = $list === '' ? null : $list;
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
     * **And not every type fits every shape** ([XIV-115]). A type holding a file
     * is not offered on a collection, because a download is addressed by module
     * and record id and a row has no address of its own. The engine refuses it on
     * the write path either way; this is the half that keeps somebody from
     * meeting the refusal after filling in a form, which is §8.3.1's rule about a
     * control that looks like it works. The shape is optional so that the
     * conversion page, which asks a question about a field rather than about a
     * shape, can ask the same list.
     *
     * @return array<string, FieldType>
     */
    private function addableTypes(?ShapeDefinition $shape = null): array
    {
        return array_filter(
            $this->fieldTypes->all(),
            static fn (FieldType $type): bool => self::configurable($type)
                && !($shape instanceof CollectionDefinition && $type instanceof HoldsAFile),
        );
    }

    /**
     * The types this field could become ([XIV-146]).
     *
     * {@see self::addableTypes()} minus two things, and both subtractions are
     * decisions rather than plumbing.
     *
     * **The type it already has**, because there is nothing to convert and the
     * engine refuses it anyway; offering it would be offering a rewrite of a
     * whole column to no purpose.
     *
     * **Every type that {@see NeedsAnAnswer}**, which today is `choice` and
     * `reference`. Not because the engine could not convert into one: it could,
     * and the refusal it would meet is the ordinary one about an unanswered
     * option. It is that a conversion decides **what values are**, and those two
     * types need to be told what the values *mean* before they can decide
     * anything: which list this is a choice between, which module this points
     * at. That is a decision of its own, asked on the add form where the field
     * is being made, and asking it here would put two unrelated decisions on one
     * page where the report underneath is about only one of them. A field can
     * still become a `choice`: add one, convert nothing, and move the values
     * across with the importer.
     *
     * Everything the conversion page does *not* ask about follows from the same
     * rule, and it is worth saying out loud because it looks like an omission.
     * The new type's own settings, a maximum length, a country to read numbers
     * against, are not on any of these pages. A conversion changes the type and
     * nothing else, and the field's own form draws those the moment it is done,
     * which is where they are edited every other day of the week.
     *
     * @return array<string, FieldType>
     */
    private function convertibleTypes(FieldDefinition $field): array
    {
        return array_filter(
            $this->addableTypes(),
            static fn (FieldType $type): bool => $type->key() !== $field->getType()
                && !$type instanceof NeedsAnAnswer
                // **And nothing that holds a file** ([XIV-115]), which is the
                // same argument the two above make, taken to its end. A
                // conversion decides what existing values *are*, and there is no
                // reading of a date or a sentence that is a file this
                // installation wrote: the only way bytes get onto a record is an
                // upload. Offered, it would refuse every non-empty column and
                // succeed on empty ones, which is a page that works exactly when
                // it does not matter.
                && !$type instanceof HoldsAFile,
        );
    }

    /**
     * Whether the editor can ask everything a type cannot work without.
     *
     * Static and public so that the test can plant a violation against it — a
     * hypothetical type needing something nobody drew — without a container, a
     * tenant or a request. The rule is one line of arithmetic over two
     * declarations that live in different layers: what the type says it needs,
     * and what {@see self::optionsOf()} says the editor draws for it. A need that
     * is not among those, because no capability in {@see self::PER_TYPE} is keyed
     * by it or because this type does not declare the one that is, is a question
     * the editor has no way of asking.
     *
     * Since [XIV-163] "the editor draws it" means "it is on the form for this
     * type", which is what {@see self::optionsOf()} returns and what the add form
     * is built from. That is a narrower claim than the old one and a truer one:
     * before, the list said which types *could* have a control and a template
     * decided separately whether to draw it.
     */
    public static function configurable(FieldType $type): bool
    {
        if (!$type instanceof NeedsAnAnswer) {
            return true;
        }

        $offered = self::optionsOf($type);

        foreach ($type->needs() as $answers) {
            foreach ($answers as $option) {
                if (!\in_array($option, $offered, true)) {
                    // **Every** way of answering, not merely one of them
                    // (XIV-127). A type offering two answers of which the editor
                    // can only ask for one is a type this form can finish, and
                    // it is also a type whose second answer is unreachable from
                    // the only screen there is — which is the same silent gap
                    // XIV-144 closed, one level in. The stricter reading costs
                    // nothing today and is the one that keeps going red for the
                    // right reason.
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Every option this type declares, in the order the forms draw them
     * ([XIV-163]).
     *
     * **The whole content of a type's form, decided by the type.** This is
     * {@see self::PER_TYPE} read from the other end: that list says which
     * capability owns each option, and this asks one type which of them it has.
     * Nothing anywhere keeps a per-type list of controls, which is the property
     * XIV-144 bought and this ticket spends: giving each type a form of its own
     * is only safe while "what this form contains" is derived rather than
     * written down.
     *
     * Static and pure, so the test that guards the whole arrangement can call it
     * on a type it invented.
     *
     * @return list<string>
     */
    public static function optionsOf(FieldType $type): array
    {
        $offered = [];

        foreach (self::PER_TYPE as $option => $capability) {
            if ($type instanceof $capability) {
                $offered[] = $option;
            }
        }

        return $offered;
    }

    /**
     * And which of those the form in front of somebody actually draws a box for.
     *
     * Two subtractions from {@see self::optionsOf()}, and both are decisions
     * rather than plumbing:
     *
     *  * **the options with pages of their own** ({@see self::OWN_PAGE}) are
     *    linked rather than drawn, except that a `choice` field's list is drawn
     *    while the field is being added, because a page belonging to a field
     *    cannot be opened before the field exists and the engine will not write
     *    the field without it;
     *  * **what a period is exclusive within is a module's question**, on the
     *    same terms `unique` is refused on a collection's field: within one
     *    parent record and across the whole table are different rules and the
     *    engine will not guess (§7). The engine refuses it there too; this is the
     *    control simply not being offered where there is no honest answer.
     *
     * @return list<string>
     */
    private static function drawnFor(?FieldType $type, ShapeDefinition $shape, bool $adding): array
    {
        if ($type === null) {
            return [];
        }

        $elsewhere = $adding
            ? array_diff(self::OWN_PAGE, [ChoiceFieldType::CHOICES])
            : self::OWN_PAGE;

        return array_values(array_filter(
            self::optionsOf($type),
            static fn (string $option): bool => !\in_array($option, $elsewhere, true)
                && ($option !== ExclusiveWithin::OPTION || $shape instanceof ModuleDefinition),
        ));
    }

    /**
     * Everything a type's form needs in order to draw its own options and
     * nothing else.
     *
     * One method for both forms, because "which controls does this type have"
     * has one answer and two pages asking it separately is how the two pages
     * would come to disagree. What differs between them is the `adding` flag
     * above and nothing else.
     *
     * The answer lists are prepared here rather than in the template because
     * Twig has no `instanceof` and should not grow one so that a page can
     * interrogate the container. They are cheap and are handed over whether or
     * not this type draws the control that uses them: a select with no control
     * to sit in costs an unused variable, and deciding per option which lists to
     * prepare would be a second per-type list, which is the thing this ticket
     * exists to stop having.
     *
     * @return array<string, mixed>
     */
    private function controlsFor(?FieldType $type, ShapeDefinition $shape, bool $adding, Request $request): array
    {
        return [
            'options' => self::drawnFor($type, $shape, $adding),
            // Whether the `unique` checkbox is drawn at all (XIV-113). It is one
            // of the four rules that are about a field rather than about its
            // type, so it is on every one of these forms, except on a type whose
            // values are a list, where the engine refuses the flag outright
            // because the index behind it would enforce a rule nobody asked for.
            //
            // Drawn and then refused would be §8.3.1's own defect: a control that
            // looks like it works. The refusal is still there and is still what
            // makes this true for the importer and the console; this only stops a
            // customer meeting it by ticking a box.
            'uniqueOffered' => !$type instanceof HoldsSeveralValues,
            'autocompleteChoices' => Autocomplete::settable(),
            // Every country there is, named in the language being read (XIV-114).
            // From symfony/intl rather than a list kept here, for the reason the
            // currency and timezone pickers give: a copy of the country list
            // maintained by hand is a copy that is wrong.
            'regionChoices' => Countries::getNames($request->getLocale()),
            // And every module this customer has, for the option whose answer is
            // another module (XIV-144). Their own labels rather than the
            // blueprints'. §6.1 says the installed definition is the truth, and
            // a customer who renamed Contacts to Kunden should be choosing
            // "Kunden" here.
            'moduleChoices' => $this->installedModules(),
            // And every shared list this customer keeps, for the option whose
            // answer names one (XIV-127). Blank is a real answer here and not a
            // broken field: it means the field keeps its own options, which is
            // what every `choice` field in every tenant does today.
            'listChoices' => $this->lists->asChoices(),
            // And this shape's own fields, for the one option whose answer is a
            // field beside it rather than a value from a fixed list (XIV-136).
            'scopeChoices' => $shape->getFields(),
        ];
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

                foreach ($type->needs() as $answers) {
                    // A question is unanswered only when *none* of its answers
                    // is given (XIV-127) — a choice field pointing at a shared
                    // list has no options of its own and is finished, and
                    // marking it would be the badge saying the opposite of the
                    // truth.
                    foreach ($answers as $option) {
                        $answer = $field->getOption($option);

                        if ($answer !== null && $answer !== '' && $answer !== []) {
                            continue 2;
                        }
                    }

                    $unfinished[] = $id;

                    break;
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
