import { Controller } from '@hotwired/stimulus';

/*
 * Écran de test du formulaire d'un dispositif (templates/device/_bench.html.twig) :
 * il fait tourner ses écrans fictifs à la durée saisie, sans l'enregistrer, et
 * recopie le libellé tapé. Une durée hors des bornes du champ garde la durée
 * enregistrée. Indépendant du contrôleur « display » de la page d'affichage.
 */
export default class extends Controller {
    static targets = ['screen', 'clock', 'labelInput', 'labelOutput', 'durationInput'];

    static values = { savedDuration: Number, emptyLabel: String };

    connect() {
        this.index = 0;
        this.clockFormat = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' });
        this.clockTimer = setInterval(() => this.#tick(), 1000);
        this.updateLabel();
        this.#schedule();
    }

    disconnect() {
        clearInterval(this.clockTimer);
        clearTimeout(this.rotationTimer);
    }

    updateLabel() {
        if (!this.hasLabelInputTarget || !this.hasLabelOutputTarget) {
            return;
        }

        const label = this.labelInputTarget.value.trim();
        this.labelOutputTarget.textContent = label || this.emptyLabelValue;
        this.labelOutputTarget.classList.toggle('text-slate-500', !label);
    }

    // Une nouvelle durée s'applique tout de suite : l'écran en cours repart pour cette durée.
    updateDuration() {
        this.#schedule();
    }

    #rotate() {
        this.index = (this.index + 1) % this.screenTargets.length;
        this.screenTargets.forEach((screen, index) => {
            screen.hidden = index !== this.index;
        });
        this.#schedule();
    }

    #schedule() {
        clearTimeout(this.rotationTimer);
        this.rotationTimer = setTimeout(() => this.#rotate(), this.#duration() * 1000);
    }

    #duration() {
        if (this.hasDurationInputTarget) {
            const input = this.durationInputTarget;
            const seconds = Number(input.value);

            if (input.value !== '' && Number.isInteger(seconds) && seconds >= Number(input.min) && seconds <= Number(input.max)) {
                return seconds;
            }
        }

        return this.savedDurationValue;
    }

    #tick() {
        const time = this.clockFormat.format(new Date());
        this.clockTargets.forEach((clock) => {
            if (clock.textContent !== time) {
                clock.textContent = time;
            }
        });
    }
}
