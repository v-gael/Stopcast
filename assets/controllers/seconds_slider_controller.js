import { Controller } from '@hotwired/stimulus';

// Curseur de durée (SecondsSliderType) : recopie la valeur à côté du libellé et dans aria-valuetext.
export default class extends Controller {
    static targets = ['input', 'output'];

    update() {
        const seconds = this.inputTarget.value;
        this.outputTarget.textContent = `${seconds} s`;
        this.inputTarget.setAttribute('aria-valuetext', `${seconds} secondes`);
    }
}
