import { Controller } from '@hotwired/stimulus';

/*
 * The payment-part tick, and the two answers beside it that can make it a lie
 * (XIV-164).
 *
 * A decoration is composed onto the *PDF* after the template has said
 * everything it gets to say, so there is no such thing as a decorated .docx and
 * the pipeline is written never to make one. The chooser is one form with a
 * format select in it, though, which means the tick and the answer that
 * invalidates it live on the same screen: choose the Word format and a ticked
 * box promising a payment slip is a promise nobody is going to keep. Same on
 * the send chooser, where "no attachment" leaves the tick describing a document
 * that is not going anywhere.
 *
 * So the tick appears and disappears with the answers it depends on, which is
 * the same rule the server already applies to the other two reasons it might be
 * absent: a module offering no decoration and a tenant whose settings could not
 * produce one both draw nothing at all, silently, rather than a disabled
 * control explaining itself. Those two are decided while the page is built and
 * cannot change under somebody's hands; these two can, and this is what keeps
 * all four looking the same.
 *
 * **Hidden and disabled, not merely hidden.** A hidden checkbox is still a
 * successful control and still submits, so hiding alone would leave the form
 * asking for a decoration the person can no longer see. Disabling is what makes
 * the request agree with the screen. Nothing here decides anything the server
 * does not decide again: a .docx goes out undecorated whether or not this file
 * ever loaded.
 *
 * **There is no browser test of this, and that is a decision rather than an
 * omission.** Every end-to-end test in this suite shares one tenant, because
 * exactly one hostname resolves from both the browser's container and the
 * application's (`compose.override.yaml`), and everything they write is
 * committed so the other process can see it. A tick to hide needs a payment
 * part on offer, which needs that shared tenant to be given a currency and a
 * region, and `FiguresInEveryLanguageTest` asserts its figures on the explicit
 * basis that the tenant has chosen neither. So the coverage here is the
 * wiring: `InvoiceQrBillPdfTest` asserts the controller name, the targets and
 * the action the form declares, which is what catches a renamed identifier on
 * one side of the pair. The behaviour above is deliberately small enough to
 * read.
 */
export default class extends Controller {
	static targets = ['field', 'format', 'document'];

	connect() {
		this.refresh();
	}

	refresh() {
		const decorated = this.formatTarget.value === 'pdf' && this.attaching();

		this.fieldTargets.forEach((field) => {
			field.hidden = !decorated;

			field.querySelectorAll('input').forEach((input) => {
				input.disabled = !decorated;
			});
		});
	}

	/*
	 * Whether there is a document for a decoration to go on. Always true on the
	 * download chooser, which has no attachment picker: what is being decorated
	 * there is the thing being downloaded.
	 */
	attaching() {
		return !this.hasDocumentTarget || this.documentTarget.value !== '0';
	}
}
