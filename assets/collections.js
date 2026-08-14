/*
 * Adding and removing rows in a record's collections — a contact's addresses.
 *
 * Enhancement only, per the rule in app.js that the forms work without
 * scripting. With no JavaScript the page still renders one blank row at the
 * bottom, so a save can always add one more, and clearing a row's fields still
 * removes it, because the server does not store an empty row. All this does is
 * save the round trip.
 *
 * The Add button is markup-hidden until this runs, so it never appears as a
 * button that does nothing.
 */

const ROW_CLASS = 'row-of-collection border-top pt-3 mt-3';

function buildRow(holder) {
	const row = document.createElement('div');
	row.className = ROW_CLASS;
	// Symfony's prototype numbers the new row's fields; __name__ is its
	// placeholder for that index.
	row.innerHTML = holder.dataset.prototype.replace(/__name__/g, holder.dataset.nextIndex);
	holder.dataset.nextIndex = String(Number(holder.dataset.nextIndex) + 1);

	const remove = document.createElement('button');
	remove.type = 'button';
	remove.className = 'btn btn-outline-danger btn-sm';
	remove.dataset.collectionRemove = '';
	remove.textContent = 'Remove';
	row.append(remove);

	return row;
}

document.addEventListener('click', (event) => {
	const add = event.target.closest('[data-collection-add]');

	if (add) {
		const holder = add.parentElement.querySelector('[data-collection]');
		holder.append(buildRow(holder));
		return;
	}

	const remove = event.target.closest('[data-collection-remove]');

	if (remove) {
		remove.closest('.row-of-collection').remove();
	}
});

for (const button of document.querySelectorAll('[data-collection-add]')) {
	button.hidden = false;
}
