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
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Form\ModuleRecordType;
use Xivi\Core\Lifecycle\Lifecycles;
use Xivi\Core\Metadata\AvailableVariants;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Record\DerivedValues;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

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

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        private readonly RecordSubmission $submission,
        private readonly DerivedValues $derived,
        private readonly AvailableVariants $variants,
        private readonly Lifecycles $lifecycles,
        private readonly TranslatorInterface $translator,
    ) {
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

        $saved = $this->submission->save($definition, $record, $submitted['fields'], $rows, $this->currentUserId());

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
     * @param array<string, mixed> $initial
     *
     * @return array<string, mixed>
     */
    private function withLiveTotals(ModuleDefinition $definition, array $initial): array
    {
        // Empty on the first render, when the form is being built to *produce*
        // these values rather than from them — and the stored figures are
        // already the right ones to show.
        if ($this->formValues === []) {
            return $initial;
        }

        /** @var array<string, mixed> $fields */
        $fields = $this->formValues['fields'] ?? [];
        $derivation = $this->derived->preview($definition, $fields, $this->submission->rows($definition, $this->formValues));

        if ($derivation === null) {
            return $initial;
        }

        foreach ($definition->getFields() as $field) {
            if ($field->isDerived()) {
                $initial['fields'][$field->getKey()] = $derivation->fields[$field->getKey()] ?? null;
            }
        }

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

                foreach ($collection->getFields() as $field) {
                    if ($field->isDerived()) {
                        $initial['collections'][$collection->getKey()][$row['index']]['fields'][$field->getKey()]
                            = $row['data'][$field->getKey()] ?? null;
                    }
                }
            }
        }

        return $initial;
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

            return $record;
        }

        return $this->records->find($definition, $this->recordId) ?? throw $this->createNotFoundException();
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
