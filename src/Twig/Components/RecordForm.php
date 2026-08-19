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

namespace App\Twig\Components;

use App\Record\RecordSubmission;
use App\Tenant\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Form\ModuleRecordType;
use Xivi\Core\Lifecycle\Lifecycles;
use Xivi\Core\Metadata\AvailableVariants;
use Xivi\Core\Metadata\FieldGroup;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Money\DefaultVatMode;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Record\CollectionLimit;
use Xivi\Core\Record\DerivedValues;
use Xivi\Core\Record\DuplicateValue;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRefused;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\SubmittedRows;

/**
 * The record form, owned end to end by a Live Component — the third XIV-29
 * spike, and the one the library documents.
 *
 * The first spike put the whole form in a component but left the save in the
 * controller, and lost every validation message doing it. The second kept the
 * controller and scoped the component to one collection, which works and is not
 * a pattern the documentation supports. This one does what the documentation
 * says: **the component owns the form, the actions and the submit.**
 *
 * So the controller no longer receives a POST at all. `new` and `edit` are GET
 * routes that render a page with this component on it, and everything that used
 * to happen in `ModuleController::edit()` happens in {@see self::save()} —
 * through {@see RecordSubmission}, which is where that logic should have lived
 * anyway and is the part of this spike worth keeping whatever is decided.
 *
 * **Live components are controllers**, so `#[IsGranted]` works here exactly as
 * it does on a route. What differs is where the subject comes from: on a route
 * the module is a URL segment, and here it is a prop — signed, so not
 * tamper-able, but a different place to make the argument from.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsLiveComponent('RecordForm')]
final class RecordForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    /** Scalars only: a prop is a signed attribute in the page, and travels as JSON. */
    #[LiveProp]
    public string $module = '';

    #[LiveProp]
    public ?int $recordId = null;

    /** Which kind a *new* record is (§5.5) — settled before the form exists. */
    #[LiveProp]
    public ?string $variant = null;

    /**
     * What a record made from another one starts with (XIV-19): its own values,
     * and the rows copied across.
     *
     * Both are props because the component is rebuilt from nothing on every
     * action and would otherwise forget them the first time somebody pressed a
     * button — and the header half is easy to forget, because the page it came
     * from looks right until something asks which order the invoice is for.
     *
     * @var array<string, mixed>
     */
    #[LiveProp]
    public array $seededFields = [];

    /** @var array<string, list<array<string, mixed>>> */
    #[LiveProp]
    public array $seeded = [];

    /**
     * Why this submission was refused before a form was built, if it was
     * (XIV-90).
     *
     * **Not a prop, deliberately.** It is a fact about the request that has just
     * arrived rather than about the component's state, and it is re-established
     * from the values on every request that carries them — so a client cannot
     * clear it by leaving it out, and nothing has to be kept in step with it
     * across a round trip.
     */
    private ?TranslatableMessage $refusal = null;

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        private readonly RecordSubmission $submission,
        private readonly DerivedValues $derived,
        private readonly AvailableVariants $variants,
        private readonly Lifecycles $lifecycles,
        private readonly TranslatorInterface $translator,
        // Which of its own fields a module says holds the VAT mode, and what this
        // installation prices in (XIV-116) — see applyVatMode(), which is the
        // only thing either of them is for. The registry rather than the
        // metadata, because a `LineTotals` declaration is part of what the module
        // *is* and a customer's definitions are what they have made of it.
        private readonly ModuleRegistry $modules,
        private readonly DefaultVatMode $vatMode,
    ) {
    }

    /**
     * How long the submitted collections are, before anything builds a form out
     * of them (XIV-90).
     *
     * **This is the earliest moment the number exists and the last one before it
     * gets expensive.** The library has just finished putting the request's
     * values onto `$this->formValues` — a plain array of strings, whichever of
     * the two post shapes they arrived in ({@see SubmittedRows::in()} names both)
     * — and nothing has yet built a form out of them. The form is lazy: the trait
     * instantiates it on the first `getFormView()`, which is a render or an
     * action away. Counting here therefore costs a `count()` per collection and
     * an over-long submission never reaches the four hundred and one row forms
     * that would have been built to discover the same number.
     *
     * **The refusal is `CollectionLimit`'s, word for word.** A reader cannot tell
     * which layer caught them and must not be able to: the writer's guard is
     * still there, still independent, and still the thing that makes the limit
     * true for the importer and for anything else holding `RecordWriter`. This is
     * a cheap check in front of an expensive path, and the sentence, the limit
     * and the count all come from the same place they always did.
     *
     * **Not a `#[LiveAction]`,** so it cannot be reached by name from a request;
     * it is a lifecycle hook the library calls, which is why it is public.
     */
    #[PostHydrate]
    public function countRowsBeforeBuildingTheForm(): void
    {
        $definition = $this->definition();
        $counted = SubmittedRows::in($definition, $this->formValues);

        if (!$counted->readable) {
            // A different answer from "too long", and kept different on purpose:
            // there is no count to name here, so naming one would be inventing
            // it. See SubmittedRows::UNREADABLE.
            $this->refusal = new TranslatableMessage(SubmittedRows::UNREADABLE, [], 'xivi');

            return;
        }

        $key = $counted->overTheCap();

        if ($key === null) {
            return;
        }

        // The label rather than the key, because the sentence is read by whoever
        // named the collection — the same choice, for the same reason, that
        // `RecordWriter::guardCollectionSizes()` makes.
        $this->refusal = CollectionLimit::refusal(
            $definition->getCollection($key)?->getLabel() ?? $key,
            $counted->counts[$key],
        );
    }

    /**
     * Draw the refusal instead of the submission it refused (XIV-90).
     *
     * **Ahead of the trait's own re-render hook**, which is what the priority is
     * for: `ComponentWithFormTrait::submitFormOnRender()` submits
     * `$this->formValues` into the form unless something has already submitted
     * it, and submitting the very values this request refused is the expensive
     * act being avoided. Turning its flag off is the whole of the saving.
     *
     * So the form is built from the *stored* record instead — which cannot be
     * over the cap, because the writer has never let one be written — and the
     * refusal goes on the form itself, where `form_errors()` draws it. The page
     * that comes back shows the document as it stands and says why what was sent
     * was not applied to it.
     *
     * **The submitted values are left exactly as they arrived.** They go back out
     * in the component's props untouched, so nothing is lost and nothing is
     * quietly rewritten: a client that sends the same over-long payload again
     * gets the same refusal again. Emptying or truncating them here would be the
     * far worse bug — the next save would then write the shortened list over the
     * record, which is the document-that-lies §5.1 refuses at length.
     *
     * **The view is dropped after the error is added** for the reason
     * {@see self::save()} gives: a view built before an error renders without it.
     * Here nothing has built one yet, and the line is kept anyway because the
     * ordering it depends on is one method call away from changing.
     */
    #[PreReRender(priority: 100)]
    public function drawTheRefusalRatherThanTheRows(): void
    {
        if ($this->refusal === null) {
            return;
        }

        $this->shouldAutoSubmitForm = false;

        $this->getForm()->addError(new FormError($this->refusal->trans($this->translator)));
        $this->formView = null;
    }

    /**
     * Save, or come back with what is wrong.
     *
     * Everything the controller used to do on a POST, in a method reached by a
     * button rather than by a route. `submitForm()` fills the form from the
     * values the browser has been keeping; the shape validation is ours and runs
     * after it, because a record is validated against its customer's definitions
     * rather than against annotations on a class.
     */
    #[LiveAction]
    public function save(): ?Response
    {
        $definition = $this->definition();
        $record = $this->recordFor($definition);

        $this->denyAccessUnlessGranted(
            ($record->isNew() ? ModuleAction::Add : ModuleAction::Edit)->value,
            $definition->getKey(),
        );

        // A finished record is a record of what happened (§5.8). The button is
        // gone from the page, and this is the rule the button was a courtesy over.
        $lifecycle = $this->lifecycles->for($definition->getKey());

        if (!$record->isNew() && $lifecycle !== null && $lifecycle->isLocked($record)) {
            throw $this->createAccessDeniedException();
        }

        // **Already refused, before any of this could get expensive** (XIV-90).
        // After the permission and lifecycle checks rather than before them, so
        // that who may write what is still decided first and one refusal never
        // stands in for another. Returning null re-renders, and the re-render
        // hook above is what puts the sentence on the page — the same route a
        // validation failure takes, so the reader meets one kind of page.
        if ($this->refusal !== null) {
            return null;
        }

        $this->submitForm();

        /** @var array{fields: array<string, mixed>} $submitted */
        $submitted = $this->getForm()->getData();
        $rows = $this->submission->rows($definition, $this->getForm()->getData());

        if (!$this->submission->validate($definition, $this->getForm(), $submitted['fields'], $rows, $record->id)) {
            // **The view is already built by this point**, cached by
            // `submitForm()` on its way out, so errors added to the form after
            // it would render into nothing. Dropping it makes the next render
            // build a view that has them. Undocumented, and found by a test
            // that showed a refused save as a clean form.
            $this->formView = null;

            // Null re-renders the component with the messages on the fields they
            // belong to — the thing the first spike could not do.
            return null;
        }

        try {
            $saved = $this->submission->save($definition, $record, $submitted['fields'], $rows, $this->currentUserId());
        } catch (DuplicateValue|RecordRefused $clash) {
            // **The refusals the validator above could not make** (XIV-109,
            // XIV-104). One read the table and found nothing, and somebody else's
            // save landed in the moment between that read and this write, so the
            // unique index refused. The other is a module refusing from inside
            // the transaction — a voucher whose last use went to the checkout
            // that got there first, which no earlier read could have known.
            //
            // Neither wrote anything: the writer's transaction is already rolled
            // back. So the answer is the same shape as a validation failure — the
            // message on the field, the view dropped so the next render is one
            // that has it, and null to re-render the form with everything still
            // typed into it.
            $this->submission->report($clash, $this->getForm());
            $this->formView = null;

            return null;
        }

        $this->addFlash('success', $this->translator->trans('flash.saved'));

        return $this->redirectToRoute('module_show', [
            'module' => $definition->getKey(),
            'id' => $saved->id,
        ]);
    }

    #[LiveAction]
    public function addRow(#[LiveArg] string $collection, #[LiveArg] string $kind = ''): void
    {
        $shape = $this->definition()->getCollection($collection);

        // A kind the collection does not have, or one this customer cannot fill
        // in (XIV-23) — a hand-edited payload rather than a button anybody was
        // offered, and the answer to it is to change nothing.
        if ($shape === null || !$this->offers($collection, $kind)) {
            return;
        }

        // Written into the submitted values rather than into the form: the form
        // is thrown away and rebuilt from these after every action, so this is
        // the only place a change survives.
        $this->formValues['collections'][$collection][] = [
            'id' => '',
            'position' => '',
            'fields' => $kind === '' ? [] : [(string) $shape->getVariantField() => $kind],
        ];
    }

    #[LiveAction]
    public function removeRow(#[LiveArg] string $collection, #[LiveArg] int $index): void
    {
        unset($this->formValues['collections'][$collection][$index]);

        $this->formValues['collections'][$collection] = array_values(
            $this->formValues['collections'][$collection] ?? [],
        );
    }

    /**
     * The kinds each collection offers a button for, keyed by collection.
     *
     * @return array<string, array<string, string>>
     */
    public function kinds(): array
    {
        $kinds = [];

        foreach ($this->definition()->getCollections() as $collection) {
            $kinds[$collection->getKey()] = $collection->hasVariants() ? $this->variants->of($collection) : [];
        }

        return $kinds;
    }

    public function moduleDefinition(): ModuleDefinition
    {
        return $this->definition();
    }

    /**
     * The record's own fields, in the runs the customer put them in (XIV-119).
     *
     * **Asked here rather than in the template**, for one reason: the variant.
     * This component builds its form for the variant of the record it is
     * holding, and a template working that out for itself would be a second
     * place deciding which fields exist — the first time somebody edited a
     * company, the grouping would be a person's. So the component answers with
     * the same variant it built the form with, and the template draws what it is
     * given.
     *
     * The grouping itself is {@see ModuleDefinition::getFieldGroupsFor()}'s and
     * is deliberately not this class's: the record page calls the very same
     * method, which is what stops a form in four sections sitting beside a
     * record page in one flat list.
     *
     * **It does not touch the form tree**, and that is the design rather than
     * convenience. Symfony's own way to group controls is `inherit_data`, and it
     * would work — but it moves the grouping into the form, and the form is
     * where the submitted array is shaped, where `data-model` paths are built
     * and where {@see RecordSubmission::mapViolations()} looks a field up by key
     * among the direct children of `fields`. A presentation
     * decision that can reach any of those is no longer only presentation, which
     * is the one thing a section must never become. So the tree stays flat, the
     * template draws the runs, and a record saved with sections is byte for byte
     * a record saved without them.
     *
     * @return list<FieldGroup>
     */
    public function fieldGroups(): array
    {
        $definition = $this->definition();

        return $definition->getFieldGroupsFor($definition->variantOf($this->recordFor($definition)->data));
    }

    public function record(): Record
    {
        return $this->recordFor($this->definition());
    }

    /**
     * When the form tells the server it changed (XIV-32).
     *
     * The trait's default is `on(change)|*`, which fires when a field is left —
     * so a total would appear only once somebody tabbed out of the price, which
     * is after the moment they wanted it. `debounce(400)|*` follows the typing
     * instead, and the debounce is what keeps a five-digit number to one request
     * rather than five.
     *
     * 400ms is a guess with a reason: below about 250 a fast typist generates a
     * request per keystroke, above about 600 the figure feels detached from the
     * typing that caused it.
     *
     * The trait documents overriding this as the way to change it — the
     * alternative, an `attr` on `form_start`, happens to win today and only
     * because of the order two things write the same attribute.
     *
     * @phpstan-ignore method.unused (the trait calls it; PHPStan does not see a
     *                 trait's call reach the copy this class overrides it with —
     *                 checked against the rendered form, which carries the
     *                 debounce this returns)
     */
    private function getDataModelValue(): string
    {
        return 'debounce(400)|*';
    }

    /** @return FormInterface<array<string, mixed>> */
    protected function instantiateForm(): FormInterface
    {
        $definition = $this->definition();
        $record = $this->recordFor($definition);

        return $this->createForm(
            ModuleRecordType::class,
            $this->withLiveTotals($definition, $this->submission->initial($definition, $record, $this->seeded)),
            ['module' => $definition, 'variant' => $definition->variantOf($record->data)],
        );
    }

    /**
     * The figures, worked out from what is in the form right now (XIV-32).
     *
     * **Nothing is worked out from a submission that was refused** (XIV-90). The
     * preview below transforms the submitted values through a throwaway form, and
     * a throwaway form of four hundred and one rows costs precisely what the real
     * one costs — so the second half of "twice 140 MB" is this method, and
     * refusing here is half of the saving. There is nothing to show anyway: the
     * totals of a document that will not be saved are not figures anybody wants.
     *
     * **Why this goes into the form's initial data rather than into the
     * submitted values.** A derived field is `disabled` (XIV-16), and a disabled
     * field ignores what is submitted and keeps the data the form was built
     * with. That is the rule that stops a hand-edited request typing over a
     * total, and it is also the reason the only way to *show* a new one is to
     * build the form with it.
     *
     * **Nothing is validated and nothing is written.** `RecordSubmission::rows()`
     * shapes the values the same way a save does — blank rows dropped,
     * inheritance filled in — and then the same derivers the writer uses do the
     * arithmetic, through `Money\Amount` and its one rounding rule (§5.9). What
     * does not happen is the shape validation: somebody who has typed `2.` is
     * mid-number, not wrong, and only {@see self::save()} has an opinion about
     * that.
     *
     * **The values are the form's, transformed** — see {@see self::asSubmitted()}.
     * Reading `$this->formValues` directly is the bug this feature shipped with.
     *
     * @param array<string, mixed> $initial
     *
     * @return array<string, mixed>
     */
    private function withLiveTotals(ModuleDefinition $definition, array $initial): array
    {
        // Empty on the first render, when the form is being built to *produce*
        // these values rather than from them — and the stored figures are
        // already the right ones to show.
        if ($this->formValues === [] || $this->refusal !== null) {
            return $initial;
        }

        $submitted = $this->asSubmitted($definition, $initial);

        /** @var array<string, mixed> $fields */
        $fields = $submitted['fields'] ?? [];
        $derivation = $this->derived->preview($definition, $fields, $this->submission->rows($definition, $submitted));

        if ($derivation === null) {
            return $initial;
        }

        foreach ($definition->getFields() as $field) {
            if ($field->isDerived()) {
                $initial['fields'][$field->getKey()] = $derivation->fields[$field->getKey()] ?? null;
            }
        }

        $generated = $this->generatedKind($definition);

        foreach ($definition->getCollections() as $collection) {
            // A derived collection is not on the form at all — the VAT table is
            // read on the record's page, not typed into here.
            if ($collection->isDerived()) {
                continue;
            }

            foreach ($derivation->rowsOf($collection->getKey()) as $row) {
                // The index the row came in with, carried through the derivation
                // rather than inferred from where it ended up.
                if (!isset($row['index'])) {
                    continue;
                }

                // **A row the engine owns is written back whole** (XIV-104). Every
                // other row on the form gets only its derived columns back,
                // because the rest are what somebody is currently typing and
                // handing them their own keystrokes back is at best a no-op. A
                // discount line has nobody typing into it: its text, its
                // quantity, its price and its rate are all worked out from the
                // voucher, so all four have to follow a change to the lines above
                // it — otherwise the total moves while the line that explains the
                // total does not.
                $whole = $generated !== null && ($row['data'][(string) $collection->getVariantField()] ?? null) === $generated;

                foreach ($collection->getFields() as $field) {
                    if ($whole || $field->isDerived()) {
                        $initial['collections'][$collection->getKey()][$row['index']]['fields'][$field->getKey()]
                            = $row['data'][$field->getKey()] ?? null;
                    }
                }
            }
        }

        return $initial;
    }

    /**
     * What is in the form right now, as the *model* sees it rather than as the
     * screen shows it.
     *
     * `$this->formValues` is what the fields are displaying, and a displayed
     * number is written in the reader's language: a price is `19.90` to somebody
     * working in English and `19,90` to somebody working in German (XIV-8 —
     * the language is per person, so both are on the same installation). The
     * derivers want neither. They want the stored form, and `Amount::of('19,90')`
     * is **null** — so previewing straight from the view values worked in English
     * and, in German, blanked every total the moment a re-render fed a formatted
     * number back in. It did not recover, because each render re-read the
     * formatting it had just produced.
     *
     * So the values go through the form, which is the thing that owns the
     * conversion. It costs one extra form build per render and buys the only
     * version of this that is correct in more than one language.
     *
     * A throwaway form rather than `$this->getForm()`: that one is being built
     * *by the caller of this method*, and asking for it here would be a loop.
     *
     * @param array<string, mixed> $initial
     *
     * @return array<string, mixed>
     */
    private function asSubmitted(ModuleDefinition $definition, array $initial): array
    {
        $probe = $this->createForm(
            ModuleRecordType::class,
            $initial,
            ['module' => $definition, 'variant' => $definition->variantOf($this->recordFor($definition)->data)],
        );

        $probe->submit($this->formValues);

        /** @var array<string, mixed> $data */
        $data = $probe->getData();

        return $data;
    }

    private function definition(): ModuleDefinition
    {
        return $this->metadata->get($this->module);
    }

    private function recordFor(ModuleDefinition $definition): Record
    {
        if ($this->recordId === null) {
            $record = new Record(data: $this->seededFields);

            if ($this->variant !== null) {
                $record->set((string) $definition->getVariantField(), $this->variant);
            }

            $this->applyVatMode($definition, $record);

            return $record;
        }

        return $this->records->find($definition, $this->recordId) ?? throw $this->createNotFoundException();
    }

    /**
     * A new document starts out priced the way the installation prices things
     * (XIV-116).
     *
     * **Here, and not in a deriver, which is the whole design.** A shop's
     * catalogue is priced on the shelf, so a new order should read it that way
     * without somebody restating it on every document — but a *stored* document
     * is a fact (§5.9), and a deriver consulting the setting on every save would
     * silently reprice every draft in the building the day somebody changed it.
     * So the setting seeds the field once, at the moment a blank form is built,
     * and the field is what {@see \Xivi\Core\Money\DerivesTotals} reads from then
     * on. That is exactly the relationship §5.16 gives a payment term and a due
     * date: the rule applies when the document is made, and what the document
     * keeps is the answer rather than the rule.
     *
     * **Three things stop this writing anything**, and each of them is a case
     * that has to keep working:
     *
     * - the module declares no mode field at all, or the customer has deleted it
     *   or never taken it from the upgrade offer (§6.1, §7.2.1) — in which case
     *   there is nowhere to put an answer and no arithmetic reading one;
     * - the seed already brought one along, which is how an invoice comes out
     *   priced like the order it was made from (§5.12) rather than like today's
     *   settings page;
     * - nobody has answered the question on the profile, which is the state every
     *   installation is in until they do. Nothing is written, the record stays
     *   empty, and an empty value is read as "prices exclude VAT" — which is what
     *   every document in every tenant already is.
     */
    private function applyVatMode(ModuleDefinition $definition, Record $record): void
    {
        $key = $definition->getKey();
        $field = $this->modules->has($key) ? $this->modules->get($key)->lineTotals?->vatMode : null;

        if ($field === null || $definition->getField($field) === null || $record->get($field) !== null) {
            return;
        }

        $record->set($field, $this->vatMode->mode()?->value);
    }

    /**
     * Which kind of row this module's own code generates, if any (XIV-104).
     *
     * Read from the blueprint rather than from the customer's definitions, for
     * the reason {@see AvailableVariants} gives: what the
     * engine owns is a fact about the module, and what the customer has made of
     * it is a fact about them. Renaming the label does not hand them the rows.
     */
    public function generatedKind(ModuleDefinition $definition): ?string
    {
        $key = $definition->getKey();

        return $this->modules->has($key) ? $this->modules->get($key)->lineTotals?->discountKind : null;
    }

    private function offers(string $collection, string $kind): bool
    {
        $shape = $this->definition()->getCollection($collection);

        if ($shape === null) {
            return false;
        }

        return $kind === '' ? !$shape->hasVariants() : isset($this->variants->of($shape)[$kind]);
    }

    private function currentUserId(): ?int
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}
