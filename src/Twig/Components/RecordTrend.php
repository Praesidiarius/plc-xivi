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

use App\Tenant\Security\ModuleRecord;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Xivi\Core\History\FieldTrend;
use Xivi\Core\History\FieldTrends;
use Xivi\Core\History\TrendPoint;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * What one number on this record has been worth, as a line (XIV-121).
 *
 * ## Why this is the chart that gets to exist
 *
 * [XIV-66] declined to add charting, and the reason it gave is the reason this
 * one is here: *"a chart earns its place where a trend is what is being read,
 * and nowhere else"*. A price over time is that and nothing else. The alternative
 * — the same data as a table of "on 3 March, 100.00 became 120.00" — is already
 * on this page, twice: it is what the history card says and what the timeline
 * page says at length. Nobody reads it as a series, because a column of numbers
 * is not a shape. So this is not a second way of showing what is already shown;
 * it is the one reading of that data a table cannot give.
 *
 * The position in §8.3.1 is therefore narrowed rather than reversed. A dashboard
 * of charts is still refused, and so is anything aggregating across records —
 * those want a different design and would earn the dependency separately or not
 * at all.
 *
 * ## Why it is on the record and not on the dashboard
 *
 * A price trend is about *that article*. A dashboard is what somebody sees before
 * they have picked anything, so a price chart there would need a subject chosen
 * for the reader, which is a different feature with a different question in it
 * ("which article?") and no obvious answer. On the record page the subject is
 * settled by the URL.
 *
 * ## Why it is a component rather than a variable the controller passes
 *
 * Because it has a control, and §8.3.1 has already had this argument for the
 * dashboard: **narrowing what a card shows is not navigation.** Which field is
 * plotted is not something anybody wants in a URL, in the back button or in a
 * link they send a colleague — they want the record's address to be the record's
 * address. So the card owns its own state, exactly as the follow-up lens does,
 * and the page keeps deciding only whether the card exists at all.
 *
 * The picker also is the generalisation. One chart hard-wired to `price` would
 * have been a special case with a dependency attached; a chart of *whichever
 * numeric field the reader picks* is the same code and stops the next request —
 * a stock level, a quantity, a rate — being another ticket. Which fields are on
 * offer is {@see FieldTrends}'s question and is answered from the values rather
 * than from a list of field names, so a customer's own numeric field is on the
 * list without anybody deploying anything.
 *
 * ## Permissions
 *
 * A chart is a number about records, so a reader must see nothing here about a
 * record they may not open (§8.4, [XIV-52], and the near-miss [XIV-66] records).
 * The card is drawn on the record page, which has already voted `view` on this
 * exact record — but a live component is reachable at an endpoint of its own and
 * its props are signed rather than secret, so the check is made here as well.
 * Record-level rather than module-level: somebody scoped to their own records
 * must not read a colleague's prices off an axis, and the refusal is the
 * record page's own — 404, not 403, so that a wrong answer and a forbidden one
 * are indistinguishable from outside and ids reveal nothing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsLiveComponent('RecordTrend')]
final class RecordTrend extends AbstractController
{
    use DefaultActionTrait;

    /** Scalars only: a prop is a signed attribute in the page and travels as JSON. */
    #[LiveProp]
    public string $module = '';

    #[LiveProp]
    public int $recordId = 0;

    /**
     * Which field is drawn, or null for the one this record has the most to say
     * about.
     *
     * Writable because the `<select>` in the template binds straight to it, which
     * is the whole of the interaction — there is no action to write, because
     * nothing happens beyond a re-render. Writable also means a client can put
     * anything it likes in it, which costs nothing: {@see self::getChosen()}
     * resolves it against the trends this record actually has and falls back to
     * the default, so a made-up field key draws the default chart rather than
     * an error.
     */
    #[LiveProp(writable: true)]
    public ?string $field = null;

    /**
     * This record's trends, read once however many times the template asks.
     *
     * The template asks three times — whether there is anything, what is in the
     * picker, and which one is drawn — and each would otherwise be a query and a
     * pass over its rows. Not a cache with a lifetime: a component is built,
     * rendered and thrown away inside one request.
     *
     * @var array<string, FieldTrend>|null
     */
    private ?array $trends = null;

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        private readonly FieldTrends $fieldTrends,
        private readonly ChartBuilderInterface $charts,
    ) {
    }

    /**
     * Whether this card exists at all.
     *
     * Nothing — no heading, no empty state, no "this record has no numbers" —
     * for a module with nothing numeric on it. A contact is not a record with a
     * missing chart, it is a record a chart has no opinion about, and a box
     * saying so on every contact in the installation would be the feature
     * refusing to leave (§5.18's rule, applied to a card rather than a panel).
     */
    public function hasAnything(): bool
    {
        return $this->all() !== [];
    }

    /**
     * What the picker offers, in the customer's own field order.
     *
     * @return array<string, FieldTrend>
     */
    public function getTrends(): array
    {
        return $this->all();
    }

    /**
     * The one being drawn.
     *
     * **The default is the field with the most changes**, not the first one, and
     * that is a small decision worth the sentence. An article has a price and a
     * VAT rate, and both are numbers; the rate is set once and left alone for
     * years while the price is the thing somebody actually revises. Opening on
     * the field with the most shape in it means the card is useful before
     * anybody touches the picker, which is the only state most readers will ever
     * see it in. Ties go to the customer's own field order, so the answer is
     * stable rather than dependent on how a sort happened to run.
     */
    public function getChosen(): FieldTrend
    {
        $trends = $this->all();

        if ($this->field !== null && isset($trends[$this->field])) {
            return $trends[$this->field];
        }

        $best = null;

        foreach ($trends as $trend) {
            if ($best === null || $trend->changes > $best->changes) {
                $best = $trend;
            }
        }

        // Unreachable from the template, which asks {@see self::hasAnything()}
        // first and draws none of this when the answer is no. Loud rather than
        // silent all the same: a caller that got here has skipped that question,
        // and an empty trend invented to keep it happy would be a chart of
        // nothing at all.
        return $best ?? throw new \LogicException(sprintf(
            'Record %d of module "%s" has no trend to draw; ask hasAnything() first.',
            $this->recordId,
            $this->module,
        ));
    }

    /**
     * The chart, as Chart.js will be handed it.
     *
     * ### Stepped, and stepped *after*
     *
     * A price is not a measurement taken at intervals; it holds until somebody
     * changes it ({@see TrendPoint}). Chart.js's default straight line between
     * two points would draw a price that drifted continuously from 100 to 120
     * across three weeks, which never happened and is the exact reading somebody
     * would take off the picture. `'after'` rather than `true` — which is an
     * alias for `'before'` — because the step belongs at the *next* event: the
     * value at a point is the value from that moment forward.
     *
     * ### A linear axis of milliseconds, not a category axis of labels
     *
     * A category axis spaces points evenly, which for a series of events means
     * two changes a day apart and two changes a year apart are drawn the same
     * width. That destroys the only thing the chart is for. Chart.js's proper
     * time axis wants a date adapter — one more dependency, and `date-fns`
     * unbundled through AssetMapper is larger than Chart.js itself — so the x
     * values are plain milliseconds on a linear scale and the small Stimulus
     * controller beside this file turns them back into dates for the ticks and
     * the tooltip, using the browser's own `Intl`. No dependency, one file, and
     * the formatting follows the reader's language rather than the server's.
     *
     * ### No colours here
     *
     * The line takes its colour from the stylesheet, in that same controller.
     * Naming a hex value in PHP would put the theme in two places and be wrong
     * in one of them the day somebody restyles the application.
     */
    public function getChart(): Chart
    {
        $trend = $this->getChosen();

        $chart = $this->charts->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'datasets' => [[
                'label' => $trend->field->getLabel(),
                'data' => array_map(
                    static fn (TrendPoint $point): array => [
                        // Milliseconds, because that is what JavaScript's Date
                        // takes and the controller hands them straight to it.
                        'x' => $point->at->getTimestamp() * 1000,
                        'y' => $point->value,
                    ],
                    $trend->points,
                ),
                'stepped' => 'after',
                'fill' => true,
                'pointRadius' => 3,
                'pointHoverRadius' => 5,
            ]],
        ]);

        $chart->setOptions([
            'responsive' => true,
            // The card decides the height, in CSS. Left true, Chart.js would
            // pick a height from the width and a card in a narrow column would
            // get a chart as tall as it is wide.
            'maintainAspectRatio' => false,
            'plugins' => [
                // One dataset, whose name is the heading above the chart. A
                // legend here would be that word again, in a box, taking a fifth
                // of the drawing area.
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => ['type' => 'linear', 'ticks' => ['maxTicksLimit' => 4, 'maxRotation' => 0]],
                // **Not from zero.** A price that has moved between 95 and 120 is
                // a flat line at the top of the card if the axis starts at zero,
                // and the movement is the entire content.
                'y' => ['beginAtZero' => false],
            ],
            'interaction' => ['mode' => 'nearest', 'intersect' => false],
        ]);

        $chart->setAttributes(['data-controller' => 'trend-chart']);

        return $chart;
    }

    /**
     * The trends, having checked that whoever is asking may open this record.
     *
     * Every public accessor above funnels through here, which is deliberate:
     * one place to make the check means there is no accessor that can be added
     * later without it.
     *
     * A module that is not installed, or a record that is gone, draws nothing
     * rather than failing — a page held open across somebody uninstalling a
     * module is an ordinary sequence, and this card's answer to every kind of
     * "not applicable" is the same one.
     *
     * @return array<string, FieldTrend>
     */
    private function all(): array
    {
        if ($this->trends !== null) {
            return $this->trends;
        }

        $module = $this->metadata->find($this->module);

        // **Nothing is fetched for a module with no numbers on it.** This card is
        // mounted on every record page in the installation, and a contact page
        // must not pay a second read of its own record to be told there is no
        // chart. Asking the definitions first — cached for the request already
        // (XIV-53) — makes the common case free, and the permission check below
        // is not skipped by it: there is nothing yet to be permitted to see.
        if ($module === null || $this->fieldTrends->candidates($module) === []) {
            return $this->trends = [];
        }

        $record = $this->records->find($module, $this->recordId);

        if (!$record instanceof Record) {
            return $this->trends = [];
        }

        // **The second seam** (§8.4). The record page has voted already; this is
        // the component's own endpoint answering the same question, because
        // being mounted from a page that checked is not a property this class
        // can verify. Record-level rather than module-level: somebody scoped to
        // their own records must not read a colleague's prices off an axis.
        //
        // **It draws nothing rather than refusing**, which is a deliberate
        // difference from the routes next door and worth the paragraph. A
        // controller answers a *page*, so it can answer 404 and the reader gets
        // one; this answers a card inside somebody else's page, and there is no
        // reading of "404" that a card can perform — thrown from here it becomes
        // a template rendering error and a 500, which is a worse outcome for
        // exactly the same disclosure, namely none. The card's whole vocabulary
        // for "there is nothing here for you" is drawing nothing, and it is the
        // same answer this method gives for an uninstalled module and a deleted
        // record. Nobody meets this in practice: props are checksum-signed, so
        // the only way to ask about a record is to have been handed its props by
        // a page that already granted it — this is what happens when a grant is
        // revoked while such a page is open.
        if (!$this->isGranted(ModuleAction::View->value, new ModuleRecord($module, $record))) {
            return $this->trends = [];
        }

        return $this->trends = $this->fieldTrends->forRecord($module, $record);
    }
}
