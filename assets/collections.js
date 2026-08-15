/*
 * Adding and removing rows in a record's collections — a contact's addresses.
 *
 * **On its way out** (XIV-29), and worth knowing why while it is still here.
 * It was written as an enhancement over a form that worked without scripting,
 * and that guarantee is gone (XIV-28, §8.3). Worse, its Add button is wrong for
 * a collection with kinds: it clones Symfony's prototype, a prototype is built
 * with no data, so the row comes out carrying every field of every kind with an
 * empty hidden `kind`. XIV-29 replaces it with a button per kind and a row the
 * server renders.
 *
 * What still holds and must survive that: clearing a row's fields removes it,
 * because the server does not store an empty row. A remove button is a
 * convenience over that rule, never the only way.
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
