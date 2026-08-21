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

namespace Xivi\Core\Record;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\RecordQuery;

/**
 * A module drawing its own records on §5.3's index, in place of the table
 * (XIV-178).
 *
 * **The engine owns the page and the module owns the body, and the line between
 * them is a line rather than a mechanism.** Everything above the records on that
 * page is the same on every module for a reason, and none of it is on offer
 * here: the heading, Export, Import, Templates, Email templates, Fields, New,
 * the filter form, and both empty states. What a module may replace is the
 * region between the empty state and the pager: the markup of its own records,
 * and the policy of that markup.
 *
 * ## Why this exists, and what it is a repair of
 *
 * The knowledge base wanted one card per topic instead of twenty-five rows and a
 * pager (XIV-168). What shipped was a general grouping capability in the engine:
 * a declaration on `ModuleBlueprint`, a grouper, a value object per card, a
 * widened public field-type interface and ninety-six lines of card markup in the
 * template every module shares. One module wanted it, no second module did, and
 * §1's rule is explicit: **earn the abstraction, a second concrete use case
 * before a generalization**. XIV-177 took all of it back, and this is what was
 * left standing in its place.
 *
 * So the thing to notice about this interface is how little it knows. It knows
 * that a module may have a body, that a body is a template with some data, and
 * that a body it is not offered is a table. It does not know what a card is, or
 * that grouping exists, or that a knowledge base exists. **Adding a second kind
 * of layout here, or a vocabulary of kinds, would be XIV-168 again with better
 * file hygiene**, which is the thing this ticket rejected by name.
 *
 * ## What happens when a second module wants the same body
 *
 * This is the property that makes leaving one module's markup in that module
 * cheap rather than a bet, and it is written here because the person adding the
 * third index is already reading this.
 *
 * **The template moves out of the module package into the application's
 * `templates/module/index/`, both modules name it, and nothing about this
 * interface changes.** The seam already carries a template name, so "it becomes
 * the engine's" is a file move and a string. §1's second half, *once a second
 * module needs it, it is the engine's*, is therefore satisfiable here without a
 * redesign, which is exactly what it could not have been if a module owned the
 * page instead of the body.
 *
 * What does **not** move with it is this interface growing a way to say which
 * shared body you want. Two modules naming the same template are two modules
 * that each answered the same question the same way, and that is all it has to
 * be.
 *
 * ## Found by tag, and the first answer wins
 *
 * {@see AutoconfigureTag} below means implementing this is the whole of
 * registering it, exactly as with {@see \Xivi\Core\Dashboard\DashboardWidget}.
 * The index asks each in turn and draws the first body it is handed, so two
 * providers answering for one module would be a conflict resolved by tag
 * priority rather than by an error, which is the same thing that would happen if
 * two modules declared the same key, and is not a case anybody should be in.
 *
 * **Implemented by module packages, never by `packages/control-plane`.** The
 * customer-facing image is built without the administration surface (§4.4), and
 * `ControlPlaneIsOptionalAtBuildTimeTest` reads PHP and configuration; a Twig
 * template it cannot see would be a missing file at render time on the one build
 * nobody runs the suite against. Every module package *is* in that image, which
 * is where a module's own body is wanted.
 *
 * ## A body that cannot apply returns null, and that is the ordinary case
 *
 * Null is the answer for every module on every page except the one, so it is
 * what a provider says most of the time and it is not an error. It is also how
 * §6.1 is honoured: **a body is an offer, resolved against the tenant's own
 * shape at render time**, so a provider that needs a field asks the definition
 * it was handed for that field and answers null when a customer has removed it
 * or converted it to something the layout cannot draw. The page falls back to
 * the table, which is a supported metadata change costing nothing rather than an
 * outage. It is the degradation §7.6 chose for a stale reference and §8.3.1 for
 * an unknown widget key.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AutoconfigureTag(IndexBodyProvider::TAG)]
interface IndexBodyProvider
{
    /**
     * The tag the application collects on.
     *
     * A constant rather than a string repeated in two composer packages, for
     * {@see \Xivi\Core\Dashboard\DashboardWidget::TAG}'s reason: a typo shows up
     * as a body that simply never appears, which is the failure mode with no
     * error message attached to it.
     */
    public const string TAG = 'xivi.index_body';

    /**
     * What this module draws in place of the table, or null for the table.
     *
     * **The three arguments are the three facts the page has and a module
     * cannot get for itself.** The definition is the *customer's* shape rather
     * than the blueprint (§6.1), so the provider resolves its own fields against
     * it. The query is what the filter bar and the sort in the URL asked for, so
     * a body that ignored it would be a filter form above a body that does not
     * filter. The access is this reader's own predicate (§8.4), and it is passed
     * rather than fetched so that the body and the page cannot disagree about
     * who is reading. A body counting without it would give away how many
     * records exist one integer at a time.
     *
     * **Asked once, for the module being looked at, and drawn if it answers.**
     * Unlike `DashboardWidget::panel()` there is no cheapness contract and no
     * promise to resolve later, because there is nothing to defer: a provider
     * that answers is the provider whose body is rendered on this very request.
     * That is why {@see IndexBody} carries a plain array where
     * {@see \Xivi\Core\Dashboard\WidgetPanel} carries a closure. The reason the
     * closure exists there is that most panels are asked and not drawn, and here
     * exactly one is asked and drawn.
     *
     * A provider that raises takes the page down rather than being quietly
     * skipped, on {@see \App\Dashboard\Dashboard}'s argument: an index that
     * silently fell back to a table nobody asked for would be an index nobody
     * can tell is broken.
     */
    public function bodyFor(ModuleDefinition $module, RecordQuery $query, RecordAccess $access): ?IndexBody;
}
