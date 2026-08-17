import { Controller } from '@hotwired/stimulus';

/*
 * The address, while somebody is still typing their company name (XIV-65).
 *
 * ### Why this is here and not a Live Component
 *
 * XIV-33 adopted Symfony UX Live Components and this is exactly their shape, so
 * the departure is deliberate and belongs written down where somebody will
 * reach for the obvious answer. A live component answers at
 * `/_components/{name}/{action}`, a route the bundle registers once for every
 * host this installation serves, and the component is resolved from that route's
 * parameter rather than from any route of its own. A signup component would
 * therefore keep answering on the signup host after SIGNUP_PAGE had switched the
 * page off, and on every tenant's host besides — a page that is "off" while its
 * actions still run, which is the thing XIV-64 wrote a route loader to make
 * impossible. Nothing in the bundle's configuration can say otherwise, because
 * the route is not this feature's to bind.
 *
 * So the interactive half is this: sixty lines that ask an ordinary route —
 * stamped with the signup host, forced to https, absent from the routing table
 * when either switch is off — and write three strings into the page.
 *
 * ### It writes text, never markup
 *
 * `assets/app.js` draws the line that nothing here builds HTML, and this page is
 * the one in the repository that strangers reach, so it is the last place to
 * blur it. The server answers with a name, a boolean and one already-translated
 * sentence; this sets `value` and `textContent` and toggles two Bootstrap
 * classes. A company name that contains a `<script>` is a string in a text node,
 * by construction rather than by escaping correctly.
 *
 * ### The derivation is the server's, which is the whole point of XIV-100
 *
 * There is deliberately no transliteration in this file. A copy of the rule here
 * would disagree with the server's on the first umlaut somebody types — which is
 * precisely the bug XIV-100 reported between two *server* endpoints, and it would
 * be worse here because the customer would be looking at our answer while the
 * server recorded its own. What the visitor sees is what the server said it would
 * create, or nothing.
 */
export default class extends Controller {
	static targets = ['company', 'slug', 'message'];
	static values = { url: String };

	connect() {
		/*
		 * Whether the visitor has taken the name over. Until they do, the box
		 * follows the company name; from the first keystroke in it, it is theirs
		 * and nothing overwrites it — a suggestion that keeps reappearing over
		 * something somebody typed is the most irritating possible version of
		 * this feature.
		 */
		this.edited = this.slugTarget.value !== '';
		this.timer = null;
		this.request = 0;
	}

	disconnect() {
		clearTimeout(this.timer);
	}

	/* The company box changed. */
	company() {
		this.schedule();
	}

	/* The address box changed, which hands it over for good. */
	slug() {
		this.edited = true;
		this.schedule();
	}

	/*
	 * Debounced, because this is a request per pause in somebody's typing and
	 * the endpoint behind it spends a rate-limit bucket. 400ms is about the gap
	 * between words rather than between letters.
	 */
	schedule() {
		clearTimeout(this.timer);
		this.timer = setTimeout(() => this.check(), 400);
	}

	async check() {
		const company = this.companyTarget.value.trim();
		const slug = this.edited ? this.slugTarget.value.trim() : '';

		if (company === '' && slug === '') {
			this.report('', null);
			return;
		}

		/*
		 * Answers can arrive out of order — a short name typed after a long one
		 * may come back first — so each request carries a sequence number and
		 * only the newest is allowed to write. Without this the box settles on
		 * whichever answer was slowest, which reads as the page ignoring the last
		 * thing you typed.
		 */
		const mine = ++this.request;

		const body = new FormData();
		body.append('company', company);
		body.append('slug', slug);

		let answer;

		try {
			const response = await fetch(this.urlValue, { method: 'POST', body });
			answer = await response.json();
		} catch {
			/*
			 * Offline, or the endpoint refused in a way that is not JSON. Silent
			 * on purpose: the form still works, the server still derives the name
			 * on submit, and an error message about a preview would be alarming
			 * about something that does not matter yet.
			 */
			return;
		}

		if (mine !== this.request) {
			return;
		}

		if (!this.edited && typeof answer.slug === 'string') {
			this.slugTarget.value = answer.slug;
		}

		this.report(answer.message ?? '', answer.available === true);
	}

	report(message, available) {
		this.messageTarget.textContent = message;
		this.messageTarget.classList.toggle('text-success', available === true);
		this.messageTarget.classList.toggle('text-danger', available === false);
	}
}
