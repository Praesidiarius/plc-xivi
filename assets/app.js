/*
 * The application's only JavaScript.
 *
 * Bootstrap's CSS is imported here rather than linked from a CDN: a CDN reports
 * every customer's IP to a third party on every page load, which is an odd
 * footnote under a product sold on physical data isolation. importmap:install
 * downloads it at build time and it is served from our own host.
 *
 * Bootstrap's JavaScript is imported for one thing: the tooltips on icon-only
 * buttons (XIV-8). It was deliberately absent before, and the rule it was
 * protecting still holds — **the forms work without scripting**. A tooltip is
 * an affordance on top of a button that is already labelled for screen readers,
 * so losing it costs a hint rather than a feature.
 */
import { Tooltip } from 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import './styles/app.css';
import './collections.js';

// Bootstrap does not scan for these itself; each one is constructed. The title
// attribute stays on the element either way, so a page that fails to run this
// still shows the browser's own tooltip rather than nothing.
for (const el of document.querySelectorAll('[data-bs-toggle="tooltip"]')) {
	new Tooltip(el);
}
