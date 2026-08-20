import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/*
 * Arranging a form by moving the fields, rather than by typing what their
 * numbers would have to be (XIV-165).
 *
 * ### What this actually writes
 *
 * Nothing, until the form is submitted. Every gesture below ends in the same
 * two lines: walk the table from the top, and write 10, 20, 30 into the hidden
 * `position[id]` input of each row while remembering which heading was passed
 * last, which goes into that row's `section[id]` select. So `position` is still
 * an integer in tens and still renumbered on save, exactly as §5.1 has always
 * said, and the whole shape still leaves in one POST that runs `updateField()`
 * once per field with every §5.4 refusal in front of it.
 *
 * **A save per drop was the thing to avoid**, and it is worth saying why rather
 * than leaving it as taste. It would be a request per gesture, so a fast
 * rearrangement becomes a queue; it would be an unsaved state that can
 * half-apply, so a page abandoned in the middle leaves a form somebody did not
 * ask for; and the editor's refusals would fire *during* a drag, which means a
 * warning about a section key while a row is still under the cursor. The page
 * having one save button and meaning it is not a limitation being worked
 * around.
 *
 * ### Why the order is read off the DOM instead of kept in a model
 *
 * There are three ways to move a field here, dragging it, nudging it with the
 * two buttons, and picking a heading from the select, and a model beside the table
 * would be a fourth thing to keep in step with the other three. The table is
 * already the truth: it is what somebody is looking at while they decide, and
 * it is what the drag library rearranges whether we ask it to or not. So every
 * gesture does its own small mutation of the rows and then calls `renumber()`,
 * which derives the numbers and the sections from what is now on the screen. A
 * row and the select in it cannot disagree, because one of them is computed
 * from where the other ended up.
 *
 * ### Why a heading is a row and not a list of its own
 *
 * The obvious markup is one sortable list per section. It cannot be dropped
 * into: a section with no fields yet is a run with no height, and that is
 * precisely the section somebody has just made in order to put a field in it.
 * One list with the headings inside it as ordinary rows has no such hole, and
 * the rule falls out for free: a field belongs to the last heading above it.
 *
 * A heading itself does not move. Where a section sits is stored on the module
 * and a field's membership on the field (§5.4), and this form writes fields; a
 * page that quietly wrote the module row as well would be a second write path
 * out of a form whose whole design is one. Section order stays on the sections
 * page, which is linked from the corner of this one.
 *
 * ### Why SortableJS and not the browser's own drag and drop
 *
 * The native HTML5 API is free of dependencies and wrong for this. It is not
 * implemented for touch at all, so a tablet gets nothing; dragging a `<tr>`
 * through it is inconsistent between browsers; and it is driven by a class of
 * event WebDriver cannot synthesise, which would leave the one gesture this
 * ticket is about untestable in the only layer that can see it (§8.3).
 * SortableJS is MIT, has no dependencies of its own, works under a finger, and
 * moves on pointer events a browser test can actually produce. Symfony has
 * nothing here to reach for first: there is no UX package for sorting, which
 * was checked rather than assumed. THIRD-PARTY-NOTICES.md carries the licence.
 */
export default class extends Controller {
	static targets = ['list', 'row', 'heading', 'position', 'section', 'announcement'];
	static values = { moved: String, movedInto: String };

	connect() {
		this.sortable = Sortable.create(this.listTarget, {
			/*
			 * Only the grip starts a drag. Without this every cell is a handle,
			 * and selecting the text of a label or opening the width select
			 * becomes a drag that has to be cancelled, on a page whose other
			 * controls are all things you click.
			 */
			handle: '.arrange-handle',
			/*
			 * `handle` is also what keeps the headings still: they have no grip
			 * in them, so they cannot be picked up, while remaining ordinary
			 * children of the list and therefore places a row can be dropped.
			 * `draggable` would have done the first half and taken the second
			 * away with it.
			 */
			/*
			 * The library's own mouse implementation rather than the browser's
			 * drag events, which is what `forceFallback` selects and is not a
			 * workaround for a bug.
			 *
			 * SortableJS uses the native HTML5 drag API where it exists, and that
			 * API is the wrong one twice over here. A `<tr>` handed to it
			 * produces a drag image that differs per browser and is frequently
			 * nothing at all, because the thing being dragged only has a shape
			 * inside a table. And a native drag is driven by events WebDriver
			 * cannot synthesise, so the browser test this feature was built with
			 * would have had to fake the gesture rather than perform it, which on
			 * the only layer that can see any of this is worth nothing (§8.3).
			 * The fallback path is plain pointer events, works the same under a
			 * finger, and is the one a test can drive.
			 */
			forceFallback: true,
			animation: 150,
			/* The row's own slot while it is in the air: where the drop lands. */
			ghostClass: 'arrange-dragging',
			/* And the copy that follows the cursor, styled in assets/styles/app.css. */
			fallbackClass: 'arrange-in-flight',
			/*
			 * A drop says where the row went as well, into the same live region
			 * the buttons write to. Somebody who is dragging can see the result,
			 * so this is for the reader who is doing both: a screen magnifier
			 * shows a fraction of the table at a time, and "now number 4 of 11"
			 * is the part that has just gone off the edge of it.
			 */
			onEnd: (event) => {
				this.renumber();
				this.announce(event.item);
			},
		});
	}

	disconnect() {
		this.sortable?.destroy();
		this.sortable = null;
	}

	/* The keyboard's half of the drag: one slot up, where a heading is a slot. */
	up(event) {
		const row = event.currentTarget.closest('tr');
		const above = row.previousElementSibling;

		if (above !== null) {
			this.listTarget.insertBefore(row, above);
			this.settle(row, event.currentTarget);
		}
	}

	/* And one slot down, by moving what is below it above it. */
	down(event) {
		const row = event.currentTarget.closest('tr');
		const below = row.nextElementSibling;

		if (below !== null) {
			this.listTarget.insertBefore(below, row);
			this.settle(row, event.currentTarget);
		}
	}

	/*
	 * The select said a heading, so the row goes under it.
	 *
	 * At the bottom of that section rather than the top, which is the same place
	 * a newly added field goes: appended to what is already there, so nothing a
	 * customer has already arranged is pushed down by a change they made about
	 * one field. `renumber()` then writes the select back with what the table
	 * now says, which is the value it already has. That round trip is what keeps
	 * this method from being a second opinion.
	 */
	reseat(event) {
		const row = event.currentTarget.closest('tr');

		/*
		 * A shape with no headings has a select with nothing in it but the blank
		 * option, so there is no run to move to and nothing to work out. Leaving
		 * this out would make the loop below fall through to "insert at the end",
		 * which is a rearrangement nobody asked for.
		 */
		if (!this.hasHeadingTarget) {
			return;
		}

		const wanted = event.currentTarget.value;
		/* What the row goes in front of. Null is the end of the table. */
		let seat = null;
		let found = false;

		for (const element of this.listTarget.children) {
			if (element === row) {
				continue;
			}

			if (element.dataset.section !== undefined) {
				if (found) {
					// The next heading, which is where this run stops.
					break;
				}

				if (element.dataset.section === wanted) {
					found = true;
					// An empty run: straight under its own heading.
					seat = element.nextElementSibling;
				}

				continue;
			}

			if (found) {
				seat = element.nextElementSibling;
			}
		}

		if (seat !== row) {
			this.listTarget.insertBefore(row, seat);
		}

		this.settle(row, event.currentTarget);
	}

	/*
	 * After a keyboard move: renumber, say what happened, and give the focus
	 * back.
	 *
	 * Moving a node that contains the focused element is allowed to blur it, and
	 * a button that loses the focus every time it is pressed is a button you can
	 * press exactly once, which on a control whose whole purpose is being
	 * pressed nine times in a row is the difference between working and not.
	 */
	settle(row, control) {
		this.renumber();
		this.announce(row);
		control.focus();
	}

	/*
	 * The table as it now stands, turned back into the numbers the server reads.
	 *
	 * Tens, from the top, across the whole table rather than restarting under
	 * each heading. The form draws the ungrouped fields first and then each
	 * section by its own position (§5.4), so numbering straight down the page
	 * produces exactly the run somebody is looking at; restarting per section
	 * would produce the same order by a longer route and leave two fields in
	 * different sections sharing a number, which is true but reads as a bug the
	 * first time anybody looks at the column.
	 */
	renumber() {
		let section = '';
		let position = 0;

		for (const element of this.listTarget.children) {
			if (element.dataset.section !== undefined) {
				section = element.dataset.section;

				continue;
			}

			position += 10;

			const number = element.querySelector('input[data-arrange-fields-target="position"]');
			const select = element.querySelector('select[data-arrange-fields-target="section"]');

			if (number !== null) {
				number.value = String(position);
			}

			/*
			 * Only when there are headings to be under. A collection has no
			 * select at all, and a module with no sections has one whose every
			 * row is already blank; in neither case is there anything above a
			 * row for this to have learnt.
			 */
			if (select !== null && this.hasHeadingTarget) {
				select.value = section;
			}
		}
	}

	/*
	 * One sentence into the live region, so a move is audible as well as
	 * visible.
	 *
	 * Text into a text node, never markup, which is `assets/app.js`'s line and
	 * matters here because two of the three things being interpolated are a
	 * customer's own words: a field's label and a section's heading are whatever
	 * somebody typed, and this file is one of the places where "it is only a
	 * status message" would be how that stops being escaped.
	 */
	announce(row) {
		const rows = this.rowTargets;
		const place = rows.indexOf(row) + 1;
		const heading = this.headingAbove(row);

		const sentence = (heading === null ? this.movedValue : this.movedIntoValue)
			.replace('%field%', row.dataset.fieldLabel ?? '')
			.replace('%place%', String(place))
			.replace('%total%', String(rows.length))
			.replace('%section%', heading ?? '');

		this.announcementTarget.textContent = sentence;
	}

	/* The heading this row is now under, as a word, or null for the run above them all. */
	headingAbove(row) {
		for (let above = row.previousElementSibling; above !== null; above = above.previousElementSibling) {
			if (above.dataset.section !== undefined) {
				return above.dataset.section === '' ? null : above.textContent.trim();
			}
		}

		return null;
	}
}
