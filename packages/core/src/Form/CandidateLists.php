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

use Symfony\Contracts\Service\ResetInterface;
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
 * What a reference picker may point at, read once per request instead of once
 * per row (XIV-87).
 *
 * **The problem is that a collection row is a form.** `RecordReferenceType`
 * resolves its candidates through a lazy option, and the options resolver
 * computes those per form *instance* — so an order with five hundred article
 * lines built the same picker five hundred times, at two queries and two hundred
 * `<option>` elements each. XIV-68 measured it: the picker was about 60% of a
 * long edit form's bytes and a quarter of its memory, and every one of the 973
 * queries a 500-line form made was this list being rebuilt.
 *
 * **The class this was extracted from argued against exactly this, and the
 * argument has to be answered rather than deleted.** Its comment read:
 *
 * > Deliberately not a memo on this class. Records appear between one form and
 * > the next — a test that creates a contact and then opens a form is the
 * > ordinary case — and a memo keyed by module would hand the older answer to the
 * > newer form, whose submitted id is then not among its own choices.
 *
 * That was correct when it was written, and two things have changed.
 *
 * **First, the lifetime is now expressible.** XIV-54 established the shape with
 * {@see \Xivi\Core\Record\ReferenceTargets}: a memo that implements
 * `ResetInterface` and is autoconfigured onto `kernel.reset` cannot outlive the
 * request that filled it. The staleness the old comment describes is *between*
 * requests — create in one, open a form in the next — and a memo cleared at that
 * boundary does not have it. XIV-54 also found the failure mode being guarded
 * against, a memo visibly surviving across requests under `disableReboot()`, and
 * fixed it this same way. So this is not the memo that comment refused; it is
 * the one it could not write yet.
 *
 * **Second, the scope that matters is one render.** Five hundred collection rows
 * are five hundred sub-forms of one form tree in one request, built microseconds
 * apart from data that cannot change between them. Nothing can appear in the
 * middle of that.
 *
 * **The reader is part of the key, and it is the part worth being nervous
 * about.** The list is scoped — `RecordAccess` for the current user (§8.4,
 * XIV-13) — so a memo shared between two readers would hand one of them the
 * other's scoped list, which is precisely the leak scoping the picker was for.
 * What makes a key of module-and-variant safe is that a request has exactly one
 * reader, which is a property of the request lifetime rather than of this class:
 * it is true *because* of the reset, not alongside it. There is a test that a
 * second reader in a second request gets their own list, because this is the kind
 * of thing that is only obviously fine until it is not.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CandidateLists implements ResetInterface
{
    /**
     * One request's answers, keyed by module and variant.
     *
     * @var array<string, array{choices: array<string, int>, total: int}>
     */
    private array $lists = [];

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        private readonly RecordAccessProvider $access,
    ) {
    }

    /**
     * The options and how many there really are.
     *
     * Both from one query on purpose: counting separately from the same filters
     * is how a badge comes to report the capped number as the total (XIV-35).
     * They are memoised together for the same reason — splitting them here would
     * put the cap and the count on two different lifetimes.
     *
     * @return array{choices: array<string, int>, total: int}
     */
    public function for(string $moduleKey, ?string $variant): array
    {
        return $this->lists[$moduleKey . "\0" . ($variant ?? '')] ??= $this->read($moduleKey, $variant);
    }

    /**
     * Dropped at the end of the request that filled it.
     *
     * §7.4's hazard is state outliving the context it was made in, and a picker
     * list is a particularly bad thing to leak: it is scoped to one reader, so
     * carrying it into another request could show one customer's colleague the
     * names another's may not see. Autoconfiguration puts every `ResetInterface`
     * on `kernel.reset`, so this is the whole of the arrangement.
     */
    public function reset(): void
    {
        $this->lists = [];
    }

    /** @return array{choices: array<string, int>, total: int} */
    private function read(string $moduleKey, ?string $variant): array
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
        //
        // **The same predicate for both**, or the count leaks. A total that
        // included records this reader may not see would say how many exist, one
        // integer at a time, which is what scoping the picker was for.
        $access = $this->access->accessFor($moduleKey, ModuleAction::View);
        $query = new RecordQuery(
            filters: $filters,
            sorts: self::sortByTitle($module),
            perPage: RecordReferenceType::MAX_CHOICES,
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
        $total = \count($candidates) < RecordReferenceType::MAX_CHOICES
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
