import { Controller } from '@hotwired/stimulus';

/*
 * Durée parmi des valeurs préréglées (SecondsPresetsType). Un bouton préréglé
 * écrit sa valeur dans le champ nombre, qui reste la seule donnée envoyée ;
 * « Autre » montre ce champ. Une valeur hors des préréglages, déjà enregistrée
 * ou refusée par la validation, le montre aussi.
 */
export default class extends Controller {
    static targets = ['group', 'preset', 'other', 'input'];

    connect() {
        this.custom = false;
        this.groupTarget.hidden = false;
        this.sync();
    }

    choose(event) {
        this.custom = false;
        this.inputTarget.value = event.currentTarget.dataset.value;
        // Les autres contrôleurs qui écoutent le champ (écran de test) voient le changement.
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }

    other() {
        this.custom = true;
        this.sync();
        this.inputTarget.focus();
    }

    sync() {
        const preset = this.presetTargets.find((button) => button.dataset.value === this.inputTarget.value);
        const custom = this.custom || !preset;

        this.presetTargets.forEach((button) => button.setAttribute('aria-pressed', String(!custom && button === preset)));
        this.otherTarget.setAttribute('aria-pressed', String(custom));
        this.inputTarget.hidden = !custom;
    }
}
