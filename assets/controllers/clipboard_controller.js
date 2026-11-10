import { Controller } from '@hotwired/stimulus';

/*
 * Bouton qui copie le texte de « source » dans le presse-papiers. Masqué dans
 * le HTML, il n'apparaît que si le navigateur permet la copie (page en HTTPS
 * ou sur localhost) ; le texte reste sélectionnable dans tous les cas.
 */
const FEEDBACK_DURATION_MS = 2000;

export default class extends Controller {
    static targets = ['source', 'button', 'status'];

    connect() {
        this.label = this.buttonTarget.textContent;
        this.buttonTarget.hidden = !navigator.clipboard;
    }

    // Turbo garde la page en cache : elle ne doit pas y rester sur « Copié ».
    disconnect() {
        this.reset();
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.sourceTarget.textContent);
            this.#showFeedback('Copié');
        } catch {
            this.#showFeedback('Copie impossible');
        }
    }

    reset() {
        clearTimeout(this.timeout);
        this.buttonTarget.textContent = this.label;
        this.statusTarget.textContent = '';
    }

    #showFeedback(message) {
        clearTimeout(this.timeout);
        this.buttonTarget.textContent = message;
        this.statusTarget.textContent = message;
        this.timeout = setTimeout(() => this.reset(), FEEDBACK_DURATION_MS);
    }
}
