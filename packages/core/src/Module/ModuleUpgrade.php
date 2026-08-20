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

namespace Xivi\Core\Module;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Record\RecordRepository;

/**
 * What a customer's installed module could take from its blueprint, and taking
 * it (XIV-70, docs/architecture/open-questions.md §7.2.1).
 *
 * ### The rule this is built on top of rather than around
 *
 * §6.1 says installing does not retro-fit: once a module is installed, the
 * customer's own definitions are the truth. That rule is correct and stays.
 * Contact gaining an `addresses` collection, or a `payment_terms` field
 * ([XIV-67]), does not reach into anybody's database — because a blueprint
 * quietly rewriting definitions somebody has since edited would repeal exactly
 * the thing §6.1 exists to protect.
 *
 * The cost of that rule was never the rule; it was that there was no *explicit*
 * way to say yes. A customer who installed the `basic` preset had no path to the
 * extended shape at all, and one who installed Contact last year could not get
 * the collection this year's Contact ships. So this is an offer, not a
 * migration: it is shown, it is chosen, and nothing runs it on a deploy.
 *
 * ### Additive only, and the boundary is what makes it safe
 *
 * It adds fields the blueprint has and the shape has not, and it creates
 * collections the blueprint has and the module has not. That is the whole list.
 * It does not remove a field the blueprint dropped — §5.4 decided that removal
 * keeps the values and no module author gets to take a customer's field away —
 * and it does not change a field's **type**, which is the genuinely hard half of
 * §7.2 and stays open. Above all it does not touch anything that already exists:
 * a relabelled field, a narrowed width, a reordered form and a rule somebody
 * relaxed all survive, because the only writes here are inserts.
 *
 * **A key the shape already has is never offered**, whatever it now looks like.
 * That single test is what protects a customised field, and it is deliberately
 * cruder than comparing the definition with the blueprint: a customer whose
 * `phone` is a text field called "Mobile" with a width of four has made those
 * decisions, and an upgrade that noticed the difference would only be tempted to
 * correct it.
 *
 * ### Where the offer is diffed against
 *
 * Against the **blueprint**, never against a preset, and that falls out of §6.1
 * rather than being a shortcut: nothing records which preset a module was
 * installed with, on purpose, because storing it would invite something to
 * re-apply it later. It does not need to be recorded. Every preset names a
 * subset of the blueprint's own fields, so the difference between what a
 * customer has and what the blueprint declares already covers "the extended
 * preset's extra fields" without anything having to remember the word
 * "extended".
 *
 * ### Per addition rather than all or nothing
 *
 * Fifteen additions are fifteen decisions, and the customer is allowed to want
 * four of them. All-or-nothing would be simpler here and would make declining
 * one field cost somebody the other fourteen — which, in a product whose entire
 * claim is that a customer's shape is theirs, is the wrong simplification. There
 * is no partial state to describe either: a tenant already has an arbitrary
 * subset of the blueprint, which is what §6.1 says a tenant *is*, so taking four
 * of fifteen leaves the installation in exactly the kind of state it was already
 * in. Nothing anywhere records that a module is "upgraded".
 *
 * ### A rule the records could not keep arrives switched off
 *
 * A blueprint field can be `required`, and every record that already exists is
 * empty in a field that has just appeared. Installing it required would leave a
 * customer with a module full of records nobody can save until they have all
 * been filled in — which is precisely what §5.4's "a rule cannot be switched on
 * if existing records would fail it" refuses to do to somebody. Refusing the
 * *addition* over it would be worse, because then a tenant with data could never
 * take a required field at all, so instead it arrives with the rule off and the
 * confirmation page says which ones and why. Switching it on afterwards is the
 * editor's existing conversation, with its existing count and its existing
 * refusal.
 *
 * `unique` is checked the same way and almost never bites: two records with
 * nothing in a field are not duplicates of each other (see
 * {@see RecordRepository::countViolating()}), so a field that is new everywhere
 * cannot collide. It can collide on a key whose *values* are still in storage
 * from a field somebody removed (§5.4), which is exactly the case that has to be
 * checked rather than reasoned about.
 *
 * ### A derived field arrives empty and stays that way until the next save
 *
 * Nothing here writes a value into a record. A field a `ValueDeriver` owns
 * (§5.9) — a total, a due date, a document number — belongs to the engine, and
 * this code inventing a plausible one would produce records that look right and
 * are wrong (XIV-73). So existing records get the definition and no value, the
 * deriver fills it the next time each record is saved, and the confirmation page
 * says so in as many words rather than letting somebody discover it.
 *
 * ### A module may not ask for a type change here, and that is now a decision
 *
 * §7.2 left one question open until [XIV-146] built the conversion: whether a
 * shipped module may request one for tenants that already have the field. It
 * may not, and the reason is the same rule this whole class is built on read
 * one step further. **Every write here is an insert.** An addition offers a
 * customer something they do not have, and declining costs them nothing they
 * had; a conversion restates values they typed, sometimes without a way back.
 * Those are different kinds of act and only one of them belongs on a screen
 * whose whole promise is that nothing already there is touched.
 *
 * The case for allowing it is real and is why it needed deciding rather than
 * assuming: `contact.phone` became a `phone` field in the blueprint (§5.23), so
 * a tenant installed before that is one conversion away from the shape a new
 * one has, and the module is what knows it. The answer is that the module may
 * make it *obvious* and may not make it *happen*. A customer reaches the
 * conversion from their own field, reads a report computed from their own data,
 * and agrees to it or does not. What is refused is an upgrade that carries one
 * along, and an operator's console doing it for them, which is §6.1's refused
 * retro-fit in the same hat this class already turned away once.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleUpgrade
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleInstaller $installer,
        private MetadataEditor $editor,
        private RecordRepository $records,
        private TranslatorInterface $translator,
        /**
         * The customer's connection, for the transaction {@see take()} opens.
         *
         * The same one the tenant entity manager holds, so the installer's
         * flushes join this transaction rather than running beside it — the
         * point {@see \Xivi\Core\Metadata\NumberingChange} makes at length and
         * that config/services.yaml is what actually guarantees.
         */
        private Connection $connection,
        // Whether a field can point at anything in this customer's installation
        // (XIV-104) — the same class the installer asks, so an offer and an
        // install cannot disagree about what a customer may have.
        private AvailableFields $available,
    ) {
    }

    /**
     * What this module has gained and this customer has not taken.
     *
     * @return list<ModuleAddition>
     */
    public function available(ModuleDefinition $module): array
    {
        return $this->diff($module, declined: false);
    }

    /**
     * And what they have said no to, which the screen shows separately.
     *
     * A dismissed addition is not a deleted one. It is kept visible, in its own
     * list, with a way back — a decision nobody can see is not a decision, and
     * the whole reason declines are remembered is to stop the offer nagging,
     * not to make it unreachable.
     *
     * @return list<ModuleAddition>
     */
    public function dismissed(ModuleDefinition $module): array
    {
        return $this->diff($module, declined: true);
    }

    /**
     * What taking these would do, without doing any of it.
     *
     * The counts are the expensive part of this class and they are asked for
     * here rather than on the page that lists the offers, which is the same
     * split [XIV-91] made: browsing what a module has gained should not scan
     * anybody's records table, and the one request that can afford to is the
     * confirmation.
     *
     * @param list<string> $tokens
     */
    public function plan(ModuleDefinition $module, array $tokens): ModuleUpgradePlan
    {
        $chosen = self::resolve($this->available($module), $tokens);

        $relaxed = [];
        $derived = [];
        $records = 0;
        $rows = 0;
        $counted = [];

        foreach ($chosen as $addition) {
            if ($addition->isDerived()) {
                $derived[] = $addition;
            }

            [$required, $unique] = $this->rulesFor($addition);

            if (($addition->isRequired() && !$required) || ($addition->isUnique() && !$unique)) {
                $relaxed[] = $addition;
            }

            if ($addition->kind !== AdditionKind::Field) {
                // A collection arrives as an empty table. There is nothing for it
                // to be in scope *of*, which is worth being clear about on the
                // page: creating one writes to no record at all.
                continue;
            }

            // Once per shape, not once per field: four fields landing on the
            // same module are one scope, and adding the count up four times
            // would put a number on the page that is four times the truth.
            $id = (int) $addition->shape->getId();

            if (isset($counted[$id])) {
                continue;
            }

            $counted[$id] = true;
            $held = $this->records->countAll($addition->shape);

            if ($addition->shape instanceof ModuleDefinition) {
                $records += $held;
            } else {
                $rows += $held;
            }
        }

        return new ModuleUpgradePlan($chosen, $relaxed, $derived, $records, $rows);
    }

    /**
     * Take them: one transaction, and the plan recomputed inside it.
     *
     * **Recomputed rather than repeated back from the page**, for the reason
     * [XIV-91] gives: the state the confirmation was drawn from is never
     * guaranteed to be the state the write happens in. A colleague taking the
     * same field in the next tab, or adding a field of that name by hand, means
     * the addition is simply no longer available — and an offer that is gone is
     * dropped silently rather than refused, because nothing the customer asked
     * for has failed to happen.
     *
     * Everything shares a transaction because a collection is a table *and* a
     * definition, and either without the other is a shape that cannot be read: a
     * table nothing knows about, or a definition pointing at a table that is not
     * there. Postgres rolls DDL back, so a failure half way leaves the
     * installation exactly as it was.
     *
     * @param list<string> $tokens
     * @param string|null  $locale which language to write the new labels in (§6.1, XIV-8);
     *                             null takes the application's default, exactly as installing does
     */
    public function take(ModuleDefinition $module, array $tokens, ?string $locale = null): ModuleUpgradePlan
    {
        $blueprint = $this->blueprintOf($module);

        if ($blueprint === null) {
            return new ModuleUpgradePlan([]);
        }

        return $this->connection->transactional(
            function () use ($module, $blueprint, $tokens, $locale): ModuleUpgradePlan {
                $plan = $this->plan($module, $tokens);
                $domain = $blueprint->domain();

                foreach ($plan->additions as $addition) {
                    if ($addition->blueprint instanceof CollectionBlueprint) {
                        $this->installer->adoptCollection($module, $addition->blueprint, $domain, $locale);

                        continue;
                    }

                    [$required, $unique] = $this->rulesFor($addition);

                    $this->installer->adoptField(
                        $addition->shape,
                        $addition->blueprint,
                        $domain,
                        $locale,
                        $required,
                        $unique,
                    );
                }

                return $plan;
            },
        );
    }

    /**
     * Remember that they do not want one, so it stops being offered.
     *
     * @return ModuleAddition|null what was dismissed, or null if it is no longer
     *                             on offer — taken, dismissed already, or a token
     *                             naming nothing
     */
    public function dismiss(ModuleDefinition $module, string $token): ?ModuleAddition
    {
        $addition = self::resolve($this->available($module), [$token])[0] ?? null;

        if ($addition !== null) {
            $this->editor->declineAddition($addition->shape, $addition->kind, $addition->key);
        }

        return $addition;
    }

    /** And that they have changed their mind. */
    public function restore(ModuleDefinition $module, string $token): ?ModuleAddition
    {
        $addition = self::resolve($this->dismissed($module), [$token])[0] ?? null;

        if ($addition !== null) {
            $this->editor->restoreAddition($addition->shape, $addition->kind, $addition->key);
        }

        return $addition;
    }

    /**
     * The blueprint this customer's module was grown from, if this build still
     * ships it.
     *
     * A module the deploy no longer carries has nothing to offer rather than
     * being an error, which is §6.2's answer to the same question one layer up:
     * a customer keeps what they installed, and a build that has dropped a module
     * simply stops having an opinion about it.
     */
    private function blueprintOf(ModuleDefinition $module): ?ModuleBlueprint
    {
        return $this->registry->has($module->getKey()) ? $this->registry->get($module->getKey()) : null;
    }

    /**
     * The difference between the blueprint and this customer's copy, on one side
     * of the decline line or the other.
     *
     * One method for both halves because they are one walk with one test at the
     * end of it, and two walks would be two chances for the offer and the
     * dismissed list to disagree about what exists.
     *
     * Order is the blueprint's: its own fields first, then each collection.
     * That matters more than it looks, because additions are **appended** in this
     * order — see {@see ModuleInstaller::adoptField()} — so a customer taking
     * four fields at once gets them in the order the module's author wrote them,
     * without anything being said about where they sit relative to the fields
     * that were already there.
     *
     * @return list<ModuleAddition>
     */
    private function diff(ModuleDefinition $module, bool $declined): array
    {
        $blueprint = $this->blueprintOf($module);

        if ($blueprint === null) {
            return [];
        }

        $domain = $blueprint->domain();
        $found = [];

        foreach ($blueprint->fields as $field) {
            // A field pointing at a module this customer has not got is not an
            // offer (XIV-104). The same rule the installer applies, in the other
            // place a definition can be born — and the reason a customer who
            // buys vouchers later *is* offered the order's voucher field: the
            // answer changes the day the module they lacked arrives.
            if (!$this->available->has($field, $module->getKey())) {
                continue;
            }

            if ($module->getField($field->key) !== null) {
                // Theirs already, whatever it has since been renamed, narrowed or
                // relabelled to. This is the whole of the protection a customised
                // field gets, and the whole of what it needs.
                continue;
            }

            if ($module->hasDeclined(AdditionKind::Field, $field->key) === $declined) {
                $found[] = ModuleAddition::field($module, $field, $this->label($field->label, $domain));
            }
        }

        foreach ($blueprint->collections as $collection) {
            $installed = $module->getCollection($collection->key);

            if ($installed === null) {
                if ($module->hasDeclined(AdditionKind::Collection, $collection->key) === $declined) {
                    $found[] = ModuleAddition::collection(
                        $module,
                        $collection,
                        $this->label($collection->label, $domain),
                    );
                }

                continue;
            }

            // A collection they have can have grown fields of its own, and they
            // are offered exactly like the module's — a shape is a shape (§5.1),
            // which is the claim this engine keeps making and this is one more
            // place it costs nothing to keep.
            foreach ($collection->fields as $field) {
                // And the same rule about a link into a module they have not got
                // (XIV-122). Offering an order line a "Voucher" picker would be
                // offering the empty picker the installer skipped, one shape
                // further down than XIV-104 had to think about.
                if (!$this->available->has($field, $module->getKey())) {
                    continue;
                }

                if ($installed->getField($field->key) !== null) {
                    continue;
                }

                if ($installed->hasDeclined(AdditionKind::Field, $field->key) === $declined) {
                    $found[] = ModuleAddition::field($installed, $field, $this->label($field->label, $domain));
                }
            }
        }

        return $found;
    }

    /**
     * Which of the blueprint's rules the records that already exist can keep.
     *
     * Asked of the records rather than assumed from the blueprint, because the
     * interesting case is not the empty installation: a key can have values in
     * storage without having a definition, which is what §5.4's removal leaves
     * behind, so "this field is new, therefore nothing is in it" is exactly the
     * assumption that would be wrong when it mattered.
     *
     * @return array{bool, bool} the `required` and `unique` this addition will
     *                           actually be installed with
     */
    private function rulesFor(ModuleAddition $addition): array
    {
        if ($addition->kind !== AdditionKind::Field) {
            return [false, false];
        }

        return [
            $addition->isRequired()
                && $this->records->countViolatingKey($addition->shape, $addition->key, true, false) === 0,
            $addition->isUnique()
                && $this->records->countViolatingKey($addition->shape, $addition->key, false, true) === 0,
        ];
    }

    /**
     * The offers a set of posted tokens names, in the order the *offers* are in
     * rather than the order they arrived.
     *
     * Nothing about the token is trusted: it is matched against offers computed
     * for this module a moment ago, so one naming another module's shape, an
     * addition somebody has already taken, or a string somebody made up all
     * resolve to nothing at all. That is what makes the token safe to put in a
     * form.
     *
     * @param list<ModuleAddition> $offers
     * @param list<string>         $tokens
     *
     * @return list<ModuleAddition>
     */
    private static function resolve(array $offers, array $tokens): array
    {
        return array_values(array_filter(
            $offers,
            static fn (ModuleAddition $addition): bool => \in_array($addition->token(), $tokens, true),
        ));
    }

    /**
     * A blueprint label as the person reading the offer would read it.
     *
     * The *current* locale, deliberately, and not the one the module was
     * installed in: this is a page rather than a definition. What gets written
     * into the customer's definitions when they accept is translated separately,
     * by the installer, in whatever language the request asks for — the same seed
     * rule §6.1 describes, applied at the moment of writing exactly as it was on
     * the day the module was installed.
     */
    private function label(string $key, string $domain): string
    {
        return $this->translator->trans($key, [], $domain);
    }
}
