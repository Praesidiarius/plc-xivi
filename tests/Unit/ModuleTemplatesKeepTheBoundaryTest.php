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

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No template shipped by core or by a module names an application route, or
 * translates without saying which catalogue it is translating from (XIV-178).
 *
 * ## Why a test and not a review
 *
 * §3's boundary is enforced by `deptrac`, and **deptrac cannot read Twig**. It
 * collects classes and their dependencies out of PHP; a `path('module_index')`
 * in `packages/knowledge/templates/index/cards.html.twig` is a string, in a file
 * deptrac does not open, naming a route defined by an attribute in `src/`. It
 * would work perfectly under this application and would break under any other,
 * which is exactly the class of coupling {@see \Xivi\Core\Record\RecordPageUrl}
 * was created to make impossible for PHP and had no equivalent for markup.
 *
 * Nothing had ever tempted anybody: the three templates a package shipped before
 * this were two form widgets, a mail layout and a dashboard card whose addresses
 * arrived already built. XIV-178 put a whole page body in a module package, with
 * a record link and a "see them all" link in reach of a one-word shortcut, so
 * the guard is written now, on the same discipline as planting a violation when
 * adding a layer to `deptrac.yaml`, one file type over.
 *
 * ## The two rules, and why the second is about domains rather than keys
 *
 * **A route helper is refused outright.** `path()` and `url()` are the only two,
 * and there is no legitimate use of either down here: an address a package needs
 * is asked for through a seam in core and arrives built.
 *
 * **A `trans` with no domain is refused, and a `trans` with one is allowed
 * whatever it names.** The default domain is `messages`, which is the
 * *application's* catalogue, so a bare `|trans` in a package template is a
 * package reaching into the application by omission, which is the failure with
 * no visible decision behind it. Naming the domain is the decision, and it is
 * sometimes the right one: the knowledge cards say `|trans({…}, 'messages')` for
 * the two sentences the linked-records card on `templates/module/show.html.twig`
 * already says about exactly this situation, because a second copy of "Showing
 * 10 of 47" in four languages is a second thing to keep in step for no reason
 * anybody could state. A rule refusing that too would be refusing a deliberate
 * reuse in order to catch an accident, and the accident is the one worth
 * catching.
 *
 * ## What it does not cover
 *
 * `packages/control-plane` is excluded, and the exclusion is the layering rather
 * than an exemption. The administration surface sits *above* the application,
 * and `deptrac.yaml` lets `ControlPlane` depend on `App` and never the other
 * way, so a route name in an operator's template is a package naming its own
 * application's route, which is the arrangement §3 describes. Core and the
 * modules sit below and may not.
 *
 * **Twig comments are stripped before either check**, for the reason
 * {@see MigrationsUseIdentityColumnsTest} strips PHP ones: the templates in this
 * repository argue with themselves at length, and the two that explain this rule
 * necessarily write `path()` and `trans` in prose. A check that could not tell
 * prose from markup would fail on the very files that document it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleTemplatesKeepTheBoundaryTest extends TestCase
{
    /**
     * `path(` and `url(` as calls.
     *
     * Word-bounded on the left so that `full_url(` or somebody's `image_url(` is
     * not swept up, and the bracket on the right because the bare words are
     * ordinary English.
     */
    private const string ROUTES = '/\b(?:path|url)\(/';

    /**
     * Every template shipped from a package that must not know the application.
     *
     * Core is in it as well as the modules. Core may see neither the application
     * nor a module (§3), so a route name in `packages/core/templates/` would be
     * the same break arriving from the other side.
     *
     * @return iterable<string, array{string}>
     */
    public static function packageTemplates(): iterable
    {
        $root = \dirname(__DIR__, 2);

        foreach (glob($root . '/packages/*/templates', \GLOB_ONLYDIR) ?: [] as $directory) {
            // The administration surface sits above the application and may name
            // its routes. See the class docblock.
            if (str_starts_with($directory, $root . '/packages/control-plane/')) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);

                if ($file->getExtension() !== 'twig') {
                    continue;
                }

                yield substr($file->getPathname(), \strlen($root) + 1) => [$file->getPathname()];
            }
        }
    }

    /**
     * That there is anything to check at all.
     *
     * Without this, moving the directory a package keeps its templates in would
     * empty the provider and leave both checks below passing by describing
     * nothing. That is the failure XIV-60 found in `deptrac`, where seven layers
     * collected no files and reported no violations for four months, and a green
     * that means "not checked" is worse than a red one.
     */
    public function testThereArePackageTemplatesToCheck(): void
    {
        self::assertGreaterThan(1, iterator_count(self::packageTemplates()));
    }

    #[DataProvider('packageTemplates')]
    public function testAPackageTemplateDoesNotBuildAnApplicationUrl(string $file): void
    {
        self::assertDoesNotMatchRegularExpression(
            self::ROUTES,
            self::markupOf($file),
            sprintf(
                "%s calls path() or url().\n\n"
                . "A route name belongs to the application, and a package below it may see\n"
                . "`Xivi\\Core\\` and nothing else (§3). Ask for the address instead:\n"
                . "  Xivi\\Core\\Record\\RecordPageUrl    one record's own page\n"
                . "  Xivi\\Core\\Record\\RecordListUrl    a module's list, narrowed\n"
                . "  Xivi\\Core\\Record\\RecordSearchUrl  where a picker searches\n"
                . "Build it in the service that hands this template its data and pass the string\n"
                . "down, which is what packages/invoice/src/Dashboard/UnpaidInvoicesWidget.php and\n"
                . "packages/knowledge/src/Index/TopicCards.php both do.\n\n"
                . "If the address genuinely has no seam yet, add one beside those three rather\n"
                . 'than spelling the route here. deptrac cannot see this file.',
                basename($file),
            ),
        );
    }

    #[DataProvider('packageTemplates')]
    public function testAPackageTemplateNamesTheCatalogueItTranslatesFrom(string $file): void
    {
        self::assertSame(
            [],
            self::bareTranslations(self::markupOf($file)),
            sprintf(
                "%s translates without naming a domain.\n\n"
                . "The default is `messages`, which is the application's catalogue, so a bare\n"
                . "`|trans` down here reaches into the application by omission. Say which:\n"
                . "  {{ 'dashboard.unpaid'|trans({}, 'invoice') }}   the module's own\n"
                . "  {{ 'module.card_all'|trans({}, 'messages') }}   the application's, deliberately\n\n"
                . 'Both are allowed. Saying which is what is required.',
                basename($file),
            ),
        );
    }

    /**
     * The file with its Twig comments removed.
     *
     * `{# … #}` does not nest in Twig, so a non-greedy match over the whole file
     * is the language's own rule rather than an approximation of it.
     */
    private static function markupOf(string $file): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source, sprintf('cannot read %s', $file));

        return preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;
    }

    /**
     * Every `trans` in this markup that was given no second argument.
     *
     * Scanned rather than matched, because the interesting question is whether
     * there is a comma **at the top level** of the argument list, and a template
     * that passes parameters passes them as `{'%count%': n}`, a hash with its own
     * commas inside it. A regular expression that could tell those apart
     * would be one nobody could read; counting brackets can.
     *
     * Both spellings are looked for. `|trans` is the filter and is how every
     * template in this repository writes it; `trans(…)` is the function, which
     * takes the message as its first argument and the domain as its third, so a
     * two-argument call is the parameters without a domain and is refused the
     * same way.
     *
     * @return list<string> the offending fragments, so a failure says which
     */
    private static function bareTranslations(string $markup): array
    {
        $bare = [];
        $length = \strlen($markup);

        for ($i = 0; $i < $length; ++$i) {
            $at = strpos($markup, 'trans', $i);

            if ($at === false) {
                break;
            }

            $i = $at + 4;

            // `translate`, `transaction`, a CSS `transform`: this is only the
            // word when nothing wordlike follows it.
            if (preg_match('/^[A-Za-z0-9_]/', substr($markup, $at + 5, 1)) === 1) {
                continue;
            }

            $before = rtrim(substr($markup, 0, $at));
            $isFilter = str_ends_with($before, '|');
            $isFunction = $before === '' || preg_match('/[^A-Za-z0-9_.]$/', $before) === 1;

            if (!$isFilter && !$isFunction) {
                continue;
            }

            $arguments = ltrim(substr($markup, $at + 5));

            // `|trans` with nothing after it is the commonest spelling of the
            // mistake and the shortest to write.
            if (!str_starts_with($arguments, '(')) {
                if ($isFilter) {
                    $bare[] = trim(substr($markup, max(0, $at - 40), 45));
                }

                continue;
            }

            // The filter's arguments start at the parameters, the function's at
            // the message, so the domain is the second in one and the third in
            // the other. Either way it is not the first, so what is asked is
            // whether the list has more than one thing in it.
            $wanted = $isFilter ? 1 : 2;

            if (self::topLevelCommas($arguments) < $wanted) {
                $bare[] = trim(substr($markup, max(0, $at - 40), 45));
            }
        }

        return $bare;
    }

    /**
     * How many commas separate the top-level arguments of a bracketed list.
     *
     * The list starts at the opening bracket and ends at the matching one.
     * Everything nested is at a depth this does not count: a hash of parameters,
     * a call inside a call, a comma inside a quoted string.
     */
    private static function topLevelCommas(string $arguments): int
    {
        $depth = 0;
        $commas = 0;
        $quote = null;
        $length = \strlen($arguments);

        for ($i = 0; $i < $length; ++$i) {
            $character = $arguments[$i];

            if ($quote !== null) {
                if ($character === '\\') {
                    ++$i;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '(' || $character === '[' || $character === '{') {
                ++$depth;

                continue;
            }

            if ($character === ')' || $character === ']' || $character === '}') {
                --$depth;

                if ($depth === 0) {
                    return $commas;
                }

                continue;
            }

            if ($character === ',' && $depth === 1) {
                ++$commas;
            }
        }

        return $commas;
    }
}
