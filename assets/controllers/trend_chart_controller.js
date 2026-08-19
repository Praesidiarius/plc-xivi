import { Controller } from '@hotwired/stimulus';

/*
 * Dates on the axis, and the theme's own colour on the line (XIV-121).
 *
 * ### Why there is any JavaScript here at all
 *
 * `symfony/ux-chartjs` hands Chart.js a configuration serialised from PHP, and
 * JSON cannot carry a function. Two of the things a trend chart needs are
 * functions in Chart.js's API and cannot be anything else: the tick formatter on
 * the x axis, and the tooltip title. So this controller rides on the same canvas
 * and edits the options in the `chartjs:pre-connect` event, which is the
 * bundle's own extension point for exactly this.
 *
 * ### Why the axis is milliseconds rather than a time scale
 *
 * Chart.js's `time` scale needs a date adapter, and the adapter needs a date
 * library — `date-fns` unbundled through AssetMapper is larger than Chart.js
 * itself, for the sake of formatting a handful of ticks. The browser already
 * ships `Intl.DateTimeFormat`. So the server sends plain epoch milliseconds on a
 * linear scale and the four lines below turn them back into dates.
 *
 * A category axis would have needed none of this and was refused: it spaces
 * points evenly, so two changes a day apart and two a year apart draw the same
 * width, and the spacing is the entire content of a trend.
 *
 * ### The language is the document's
 *
 * `document.documentElement.lang` is what the page was rendered in, so the dates
 * on the axis are in the same language as the words around them without this
 * file being told anything. An empty `lang` falls back to the browser's own,
 * which is the correct behaviour rather than a guess.
 *
 * ### The colour comes from the stylesheet
 *
 * Bootstrap's `--bs-primary-rgb` rather than a hex value written into PHP: the
 * chart then follows the application's theme instead of being a second place
 * that has an opinion about what colour this product is. If the variable is
 * missing — a stylesheet that has not loaded — Chart.js's own default is used,
 * which is a grey line rather than no chart.
 */
export default class extends Controller {
	connect() {
		this.element.addEventListener('chartjs:pre-connect', this.format);
	}

	disconnect() {
		this.element.removeEventListener('chartjs:pre-connect', this.format);
	}

	/*
	 * Bound as a property rather than declared as a method so that `connect` and
	 * `disconnect` add and remove the *same* function reference. A bare method
	 * passed to both produces two different bound functions, and the listener is
	 * then never removed — which on a page whose cards re-render is a listener
	 * per render.
	 */
	format = (event) => {
		const options = event.detail.options;
		const locale = document.documentElement.lang || undefined;

		/* Day, month and year: a step is a day in somebody's calendar, and the
		 * time of day it was typed at is not part of the story. */
		const day = new Intl.DateTimeFormat(locale, { dateStyle: 'medium' });

		options.scales.x.ticks.callback = (value) => day.format(new Date(value));

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
			const dataset = event.detail.config.data.datasets[0];

			dataset.borderColor = `rgb(${primary})`;
			dataset.backgroundColor = `rgba(${primary}, 0.12)`;
			dataset.pointBackgroundColor = `rgb(${primary})`;
		}
	};
}
