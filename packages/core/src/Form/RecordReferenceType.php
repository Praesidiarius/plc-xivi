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
 * **Reading the candidates is {@see CandidateLists}' job** (XIV-87), and the
 * split is about lifetime rather than tidiness: this type is asked for a list
 * once per form, a collection row is a form, and five hundred order lines were
 * therefore five hundred identical reads. What is left here is the shape of the
 * control — the cap, the sentence under it, the placeholder — which is genuinely
 * per field.
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
        private readonly CandidateLists $candidates,
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
                // reference field is never rendered still asks for nothing.
                'candidates' => fn (\Symfony\Component\OptionsResolver\Options $options): array => $this->candidates->for(
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
}
