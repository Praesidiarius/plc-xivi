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
use Xivi\Core\Permission\RecordAccess;
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
                'choices' => fn (\Symfony\Component\OptionsResolver\Options $options): array => $this->choicesFor(
                    (string) $options['target_module'],
                    $options['target_variant'] === null ? null : (string) $options['target_variant'],
                ),
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
    private function choicesFor(string $moduleKey, ?string $variant): array
    {
        try {
            $module = $this->metadata->get($moduleKey);
        } catch (ModuleNotInstalled) {
            // A reference to a module this customer does not have. §7.6 has not
            // decided what that should mean; offering nothing is at least honest.
            return [];
        }

        $filters = [];

        if ($variant !== null && $module->getVariantField() !== null) {
            $filters[] = new Filter($module->getVariantField(), Operator::Equals, $variant);
        }

        // Unrestricted, and this is one of the places §7.5 has still to decide
        // about: a picker showing only your own companies is defensible, and so
        // is one that shows every company you are allowed to point at. Widening
        // it later is a change to this line; narrowing it silently would have
        // been a picker that quietly omits the right answer.
        $candidates = $this->records->findBy($module, new RecordQuery(
            filters: $filters,
            sorts: self::sortByTitle($module),
            perPage: self::MAX_CHOICES,
        ), RecordAccess::unrestricted());

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

        return $choices;
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
