import { Controller } from '@hotwired/stimulus';

/*
 * Opens the Bootstrap modal it is attached to as soon as it connects — for a
 * page that lands straight in a dialog (the document page's "Připojit
 * existující" right after "Nový dokument"). `bootstrap` is the theme's global.
 */
export default class extends Controller {
    connect() {
        bootstrap.Modal.getOrCreateInstance(this.element).show();
    }
}
