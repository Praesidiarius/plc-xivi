import { Controller } from '@hotwired/stimulus';

/*
 * Dates on the axis, and the theme's own colour on the line (XIV-121).
 *
 * ### Why there is any JavaScript here at all
 *
 * `symfony/ux-chartjs` hands Chart.js a configuration serialised from PHP, and
 * JSON cannot carry a function. Three of the things a trend chart needs are
 * functions in Chart.js's API and cannot be anything else: where the ticks on
 * the x axis go, what they say, and the tooltip title. So this controller rides
 * on the same canvas and edits the options in the `chartjs:pre-connect` event,
 * which is the bundle's own extension point for exactly this.
 *
 * ### Why the axis is milliseconds rather than a time scale
 *
 * Chart.js's `time` scale needs a date adapter, and the adapter needs a date
 * library: `date-fns` unbundled through AssetMapper is larger than Chart.js
 * itself, for the sake of formatting a handful of ticks. The browser already
 * ships `Intl.DateTimeFormat` and `Date`, which between them do everything below,
 * including the calendar arithmetic that puts a tick on tomorrow rather than
 * twenty-four hours from now. So the server sends plain epoch milliseconds on a
 * linear scale and this file turns them back into dates.
 *
 * A category axis would have needed none of this and was refused: it spaces
 * points evenly, so two changes a day apart and two a year apart draw the same
 * width, and the spacing is the entire content of a trend.
 *
 * ### The language is the document's, and it has to be a language *tag*
 *
 * `document.documentElement.lang` is what the page was rendered in, so the dates
 * on the axis are in the same language as the words around them without this
 * file being told anything. An empty `lang` falls back to the browser's own,
 * which is the correct behaviour rather than a guess.
 *
 * **This is where [XIV-174] went wrong, and the shape of it is worth keeping.**
 * Symfony writes a locale with an underscore, `de_CH`, joined from a language and
 * a region by `App\Tenant\Settings\FormattingLocale`. HTML's `lang` attribute
 * takes a BCP 47 tag, which spells the same thing `de-CH`, and `Intl` is
 * strict about the difference and does not degrade politely: `new
 * Intl.DateTimeFormat('de_CH')` throws a `RangeError`. Thrown from an event
 * listener, that abandoned the rest of this method silently, and every reader
 * whose account carried a region got an axis labelled `1'787'200'000'000`: the
 * raw millisecond count through Chart.js's own numeric formatter, which is what
 * runs when nobody has replaced it.
 *
 * `templates/base.html.twig` now writes a real tag, which is the fix. The
 * normalisation and the fallback below are still here because **this method must
 * not throw**: it does four unrelated jobs on one object, so anything that
 * escapes it takes the rest with it. In XIV-174 the tooltip and the line's colour
 * went with the axis, and nothing on screen said why.
 *
 * ### Where the ticks go, not only what they say
 *
 * Formatting alone is not enough, and [XIV-174] is where that became obvious. A
 * linear scale puts its ticks at round *numbers*, and round millisecond counts
 * have nothing to do with days: four of them across a day and a half is one
 * every fourteen hours, so two of them fall on the same date and the axis reads
 * `21.08.2026`, `21.08.2026`. Correct, and useless.
 *
 * So the ticks are placed here as well, on midnight in the reader's own zone.
 * Every label is then a different day by construction rather than by luck, and
 * the axis reads as a calendar, which is what the series is: a point says "from
 * this moment, this value", and the moments are days somebody typed something.
 * `maxTicksLimit` from the server stops being a count and becomes a budget: the
 * server still says how many labels the card has room for, and this decides
 * which days to spend them on.
 *
 * ### The same edit, twice
 *
 * `chartjs:pre-connect` covers the first paint. It does not cover a re-render:
 * `RecordTrend` is a live component, so picking another field rewrites the
 * canvas's `view` value, and the bundle's controller answers that by assigning
 * `chart.options` from the server's payload again, which is the payload without
 * any of this in it. It dispatches `chartjs:view-value-change` first, carrying
 * the same object it is about to assign, so the same listener on both events is
 * the whole of the answer.
 *
 * ### The colour comes from the stylesheet
 *
 * Bootstrap's `--bs-primary-rgb` rather than a hex value written into PHP: the
 * chart then follows the application's theme instead of being a second place
 * that has an opinion about what colour this product is. If the variable is
 * missing — a stylesheet that has not loaded — Chart.js's own default is used,
 * which is a grey-blue line rather than no chart.
 */
export default class extends Controller {
	connect() {
		this.element.addEventListener('chartjs:pre-connect', this.format);
		this.element.addEventListener('chartjs:view-value-change', this.format);
	}

	disconnect() {
		this.element.removeEventListener('chartjs:pre-connect', this.format);
		this.element.removeEventListener('chartjs:view-value-change', this.format);
	}

	/*
	 * Bound as a property rather than declared as a method so that `connect` and
	 * `disconnect` add and remove the *same* function reference. A bare method
	 * passed to both produces two different bound functions, and the listener is
	 * then never removed — which on a page whose cards re-render is a listener
	 * per render.
	 */
	format = (event) => {
		/*
		 * Two events, two shapes. `chartjs:pre-connect` carries the whole
		 * configuration as `config` and the options beside it; the re-render
		 * carries `{data, options}` and no `config`, because at that point there
		 * is no type to send and the chart already exists. Both `options` objects
		 * are the *live* ones: the bundle dispatches before it hands them over
		 * rather than a copy afterwards, which is what makes editing them here
		 * work at all.
		 */
		const options = event.detail.options;
		const data = event.detail.data ?? event.detail.config.data;

		/* Day, month and year: a step is a day in somebody's calendar, and the
		 * time of day it was typed at is not part of the story. */
		const day = dateFormatter();
		const axis = options.scales.x;

		axis.afterBuildTicks = (scale) => {
			scale.ticks = dayTicks(scale.min, scale.max, scale.options.ticks.maxTicksLimit);
		};

		axis.ticks.callback = (value) => day.format(new Date(value));

		options.plugins.tooltip = {
			...options.plugins.tooltip,
			callbacks: {
				/* The point's own date as the heading, in place of the raw
				 * millisecond count Chart.js would otherwise print. */
				title: (items) => day.format(new Date(items[0].parsed.x)),
			},
		};

		const primary = getComputedStyle(document.documentElement)
			.getPropertyValue('--bs-primary-rgb')
			.trim();

		if (primary !== '') {
			const dataset = data.datasets[0];

			dataset.borderColor = `rgb(${primary})`;
			dataset.backgroundColor = `rgba(${primary}, 0.12)`;
			dataset.pointBackgroundColor = `rgb(${primary})`;
		}
	};
}

/**
 * The page's own language, as something `Intl` will accept.
 *
 * The underscore is the whole of the translation between Symfony's spelling of a
 * locale and the web's; see the class docblock for what it cost. The `catch` is
 * for everything else a `lang` attribute might one day hold. `Intl` throws on an
 * unparseable tag rather than ignoring it, and a chart with the browser's own
 * date format is a far better outcome than a chart with none of this applied.
 */
function dateFormatter() {
	const options = { dateStyle: 'medium' };
	const tag = document.documentElement.lang.replace(/_/g, '-');

	try {
		return new Intl.DateTimeFormat(tag || undefined, options);
	} catch {
		return new Intl.DateTimeFormat(undefined, options);
	}
}

/**
 * Where the labels go: midnight, in whole days, in the reader's own zone.
 *
 * `min` and `max` are epoch milliseconds and come from the scale, so they are
 * the range actually being drawn rather than the range of the data. The result
 * is what Chart.js wants back from `afterBuildTicks`: a list of `{value}`, which
 * it then labels through `ticks.callback`.
 *
 * **Nothing but day boundaries, which is what Chart.js's own `time` scale does
 * when its unit is a day.** That is worth saying out loud, because it makes the
 * short axis look sparse: a record made yesterday spans about thirty-six hours
 * and holds exactly one midnight, so it gets exactly one label. The alternative,
 * a second tick at the left edge of the range to make the axis look busier,
 * puts a label on a moment that is not a day boundary and is therefore not the
 * same *kind* of thing as its neighbour, and an axis whose labels mean two
 * different things is worse than a thin one.
 *
 * Three things are worth saying about the arithmetic:
 *
 *  * **The step is counted in days, not in 86,400,000 milliseconds.** `setDate`
 *    lands on the same wall-clock time on the following day, so the twice-yearly
 *    23- and 25-hour day is the platform's problem and not this file's. A tick
 *    stays on midnight across a daylight-saving change; a fixed millisecond
 *    stride would have drifted an hour and started labelling the day before.
 *  * **The count used to *choose* the step is the crude one**, and that is fine:
 *    it decides how crowded the axis is allowed to be, and being one out at the
 *    far end of a decade-long range changes nothing anybody can see.
 *  * **A range with no midnight in it still gets a label.** A record created and
 *    edited the same afternoon spans a few hours; one tick in the middle says
 *    which day that was, which is the entire truth available. It is the one tick
 *    this produces that is not a midnight, and the only one that could be.
 */
function dayTicks(min, max, mostLabels) {
	const limit = Math.max(1, mostLabels || 4);

	const cursor = new Date(min);
	cursor.setHours(0, 0, 0, 0);
	cursor.setDate(cursor.getDate() + 1);

	if (cursor.getTime() > max) {
		return [{ value: min + (max - min) / 2 }];
	}

	const days = Math.floor((max - cursor.getTime()) / 86400000) + 1;
	const step = Math.ceil(days / limit);

	const ticks = [];

	// `ticks.length < limit` rather than trusting the step: rounding up can only
	// ever produce fewer than the budget, but the budget is the thing the card
	// has room for and nothing here is worth overspending it.
	while (cursor.getTime() <= max && ticks.length < limit) {
		ticks.push({ value: cursor.getTime() });
		cursor.setDate(cursor.getDate() + step);
	}

	return ticks;
}
