import { Controller } from '@hotwired/stimulus';

/*
 * Recherche en direct : envoie le formulaire après une courte pause de frappe.
 * Le formulaire vise un cadre Turbo, le champ garde donc le focus.
 */
export default class extends Controller {
    disconnect() {
        clearTimeout(this.timer);
    }

    search() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.element.requestSubmit(), 300);
    }
}
