/*
 * The application's JavaScript.
 *
 * Everything here is imported rather than linked from a CDN: a CDN reports every
 * customer's IP to a third party on every page load, which is an odd footnote
 * under a product sold on physical data isolation. importmap:install downloads
 * each package at build time and it is served from our own host.
 *
 * **The forms used to work with scripting turned off, and no longer do**
 * (XIV-28). That rule shaped real decisions — a collection form ended with one
 * blank row of every kind because switching a row's fields as somebody picked
 * would have needed scripting — and it was dropped deliberately rather than
 * eroded. What replaces it is htmx: the server still renders every page and
 * every fragment, and the browser swaps them in. Nothing here builds HTML.
 *
 * That is the line worth holding. Server-rendered stays true (§8.3); "works
 * with JavaScript off" does not.
 */
import 'htmx.org';
import { Tooltip } from 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import './styles/app.css';

// Bootstrap does not scan for these itself; each one is constructed. The title
// attribute stays on the element either way, so a page that fails to run this
// still shows the browser's own tooltip rather than nothing.
for (const el of document.querySelectorAll('[data-bs-toggle="tooltip"]')) {
	new Tooltip(el);
}
