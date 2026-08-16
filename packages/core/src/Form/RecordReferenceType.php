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
use Symfony\Component\OptionsResolver\OptionsResolver;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccessProvider;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Query\Sort;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * Picking the record a reference points at (§7.6).
 *
 * A form type rather than a plain choice list on the field type, because it has
 * to read the database to know what the choices are — and a field type is
 * handed a definition, not a connection.
 *
 * The options come from the target module's own records, named by its title
 * fields (§5.4), narrowed to a variant when the reference asks for one, so a
 * person's employer offers companies and not every contact in the system.
 *
 * A plain select, with a ceiling. Beyond a few hundred candidates this wants to
 * be a search box that queries as you type, which needs an endpoint and
 * JavaScript; a select that silently showed the first two hundred of nine
 * thousand would be worse than one that says so.
 *
 * @extends AbstractType<int|null>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordReferenceType extends AbstractType
{
    /** Above this, a dropdown has stopped being a way to find anything. */
    public const int MAX_CHOICES = 200;

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        private readonly RecordAccessProvider $access,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('target_module')
            ->setAllowedTypes('target_module', 'string')
            ->setDefault('target_variant', null)
            ->setAllowedTypes('target_variant', ['null', 'string'])
            ->setDefaults([
                'placeholder' => '—',
                // **Read once per field, by the options resolver.** Both the
                // choices and the sentence under them come from one query, and a
                // lazy option is what makes "one query" true: the resolver
                // computes it the first time it is asked and remembers it for
                // that form and no longer.
                //
                // Deliberately not a memo on this class. Records appear between
                // one form and the next — a test that creates a contact and then
                // opens a form is the ordinary case — and a memo keyed by module
                // would hand the older answer to the newer form, whose submitted
                // id is then not among its own choices.
                'candidates' => fn (\Symfony\Component\OptionsResolver\Options $options): array => $this->readCandidates(
                    (string) $options['target_module'],
                    $options['target_variant'] === null ? null : (string) $options['target_variant'],
                ),
                'choices' => static fn (\Symfony\Component\OptionsResolver\Options $options): array => $options['candidates']['choices'],
                // **A cap that says so** (XIV-35). The docblock above always
                // claimed a select showing the first two hundred of nine
                // thousand would be worse than one that says so; the ceiling was
                // implemented and the saying-so was not, so a link that cannot be
                // made looked exactly like a record that does not exist.
                // Below the ceiling there is nothing to explain, and a sentence
                // under every picker in the application would be noise.
                'help' => static fn (\Symfony\Component\OptionsResolver\Options $options): ?string => $options['candidates']['total'] > \count($options['candidates']['choices'])
                        ? 'field.picker_truncated'
                        : null,
                'help_translation_parameters' => static fn (\Symfony\Component\OptionsResolver\Options $options): array => [
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
     * Candidate records as label => id, which is the shape ChoiceType wants.
     *
     * @return array<string, int>
     */
    /**
     * The options and how many there really are.
     *
     * Both from one query on purpose: counting separately from the same filters
     * is how a badge comes to report the capped number as the total.
     *
     * @return array{choices: array<string, int>, total: int}
     */
    private function readCandidates(string $moduleKey, ?string $variant): array
    {
        try {
            $module = $this->metadata->get($moduleKey);
        } catch (ModuleNotInstalled) {
            // A reference to a module this customer does not have. §7.6 has not
            // decided what that should mean; offering nothing is at least honest.
            return ['choices' => [], 'total' => 0];
        }

        $filters = [];

        if ($variant !== null && $module->getVariantField() !== null) {
            $filters[] = new Filter($module->getVariantField(), Operator::Equals, $variant);
        }

        // Scoped, which settles the question §8.4 left open (XIV-13). A picker
        // is a list of other people's records shown on this page, so an
        // unrestricted one is a way to read the names of records somebody may
        // not open — by pointing at them and reading the label back.
        //
        // The cost is real and worth stating: somebody scoped to their own
        // records cannot link to a colleague's, and will see a picker that omits
        // the answer they wanted rather than a message saying why. That is the
        // safer half of the trade, and the one that can be widened later by a
        // grant instead of by a deploy.
        // **The same predicate for both**, or the count leaks. A total that
        // included records this reader may not see would say how many exist, one
        // integer at a time, which is what scoping the picker was for.
        $access = $this->access->accessFor($moduleKey, ModuleAction::View);
        $query = new RecordQuery(
            filters: $filters,
            sorts: self::sortByTitle($module),
            perPage: self::MAX_CHOICES,
        );

        $candidates = $this->records->findBy($module, $query, $access);

        $choices = [];

        foreach ($candidates as $record) {
            $label = self::titleOf($module, $record);

            // Two records called the same thing would collapse into one option,
            // and the second would be unpickable. The id is ugly but it is the
            // only thing guaranteed to tell them apart.
            if (isset($choices[$label])) {
                $label = sprintf('%s (#%d)', $label, (int) $record->id);
            }

            $choices[$label] = (int) $record->id;
        }

        // Only asked when the page is full: below the ceiling the answer is the
        // number already in hand, and a second query for it would be waste on
        // every picker in the application.
        $total = \count($candidates) < self::MAX_CHOICES
            ? \count($candidates)
            : $this->records->countBy($module, $query, $access);

        return ['choices' => $choices, 'total' => $total];
    }

    /**
     * Ordered by what they are called, since that is what somebody is scanning.
     *
     * @return list<Sort>
     */
    private static function sortByTitle(ModuleDefinition $module): array
    {
        $first = $module->getTitleFields()[0] ?? null;

        return $first === null ? [] : [new Sort($first->getKey())];
    }

    private static function titleOf(ModuleDefinition $module, Record $record): string
    {
        $parts = [];

        foreach ($module->getTitleFields() as $field) {
            $value = $record->get($field->getKey());

            if (\is_scalar($value) && (string) $value !== '') {
                $parts[] = (string) $value;
            }
        }

        return $parts === [] ? sprintf('%s #%d', $module->getLabel(), (int) $record->id) : implode(' ', $parts);
    }
}
