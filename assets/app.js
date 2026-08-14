/*
 * The application's only JavaScript.
 *
 * Bootstrap's CSS is imported here rather than linked from a CDN: a CDN reports
 * every customer's IP to a third party on every page load, which is an odd
 * footnote under a product sold on physical data isolation. importmap:install
 * downloads it at build time and it is served from our own host.
 *
 * Bootstrap's JavaScript is deliberately not imported — nothing here uses a
 * component that needs it, and the forms must work without scripting.
 */
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';
import './collections.js';
