import { Controller } from '@hotwired/stimulus';

/*
 * Message flash flottant (composant Flash, variante « floating »).
 * Avec « dismiss-after » (ms), il disparaît seul ; le délai repart de zéro
 * quand la souris et le focus l'ont quitté. « close » le retire tout de suite.
 */
export default class extends Controller {
    static values = { dismissAfter: Number };

    connect() {
        this.resume();
    }

    disconnect() {
        this.pause();
    }

    pause() {
        clearTimeout(this.timeout);
    }

    resume(event) {
        this.pause();

        if (!this.hasDismissAfterValue || this.#isHovered(event) || this.#isFocused(event)) {
            return;
        }

        this.timeout = setTimeout(() => this.close(), this.dismissAfterValue);
    }

    close() {
        this.pause();

        // Fondu d'opacité, gardé même avec « réduire les animations » : rien ne bouge à l'écran.
        // Le bouton a sa propre transition : seule celle du message déclenche le retrait.
        this.element.addEventListener('transitionend', (event) => {
            if (event.target === this.element) {
                this.element.remove();
            }
        });
        this.element.classList.add('opacity-0');
    }

    #isHovered(event) {
        return event?.type !== 'mouseleave' && this.element.matches(':hover');
    }

    // Pendant « focusout », le focus n'a pas encore bougé : sa destination est relatedTarget.
    #isFocused(event) {
        return this.element.contains(event?.type === 'focusout' ? event.relatedTarget : document.activeElement);
    }
}
