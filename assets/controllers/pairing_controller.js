/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const POLL_INTERVAL = 5000;
const THROTTLED_RETRY = 60000;

/*
 * Écran d'appairage : fait tourner le compte à rebours du code et interroge
 * le serveur toutes les 5 s, ou dès que le code expire. La réponse porte le
 * code à afficher (le même, ou le suivant) ; 205 dit que l'écran est appairé
 * (son cookie vient d'être posé), 401 que son cookie a été refusé : la page
 * se recharge alors, sur l'affichage ou sur un nouveau code.
 *
 * Le compte à rebours est un disque blanc dont la part restante se retire
 * dans le sens des aiguilles d'une montre en partant de midi. Il part des
 * secondes restantes envoyées par le serveur, pas de l'horloge de l'écran.
 */
export default class extends Controller {
    static targets = ['content', 'countdown'];

    static values = { url: String };

    connect() {
        this.#start();
        this.#animate();
        this.#schedulePoll();
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
        clearTimeout(this.pollTimer);
        this.request?.abort();
    }

    async poll() {
        this.request = new AbortController();

        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'text/html' },
                cache: 'no-store',
                signal: this.request.signal,
            });

            if (response.status === 205 || response.status === 401) {
                window.location.reload();

                return;
            }

            const content = new DOMParser()
                .parseFromString(await response.text(), 'text/html')
                .querySelector('[data-pairing-target="content"]');

            // Même code : rien ne change, le compte à rebours garde son élan.
            if (content && content.dataset.code !== this.contentTarget.dataset.code) {
                this.contentTarget.replaceWith(document.adoptNode(content));
                this.#start();
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            // Serveur injoignable : le code reste affiché jusqu'à l'interrogation suivante.
        }

        this.#schedulePoll();
    }

    /*
     * Nouveau code : son expiration est calée sur l'horloge de l'écran.
     */
    #start() {
        const { remainingSeconds, durationSeconds } = this.contentTarget.dataset;

        this.expiresAt = remainingSeconds === undefined ? null : performance.now() + Number(remainingSeconds) * 1000;
        this.duration = Number(durationSeconds) * 1000 || 60000;
        this.#tick();
    }

    /*
     * Un pas à chaque image affichée, pour un camembert qui avance sans à-coups.
     */
    #animate() {
        this.#tick();
        this.frame = requestAnimationFrame(() => this.#animate());
    }

    #tick() {
        if (this.expiresAt === null || !this.hasCountdownTarget) {
            return;
        }

        const remaining = Math.min(1, Math.max(0, (this.expiresAt - performance.now()) / this.duration));

        // La CSP refuse les attributs style : l'angle passe par le CSSOM.
        this.countdownTarget.style.setProperty('--elapsed', `${((1 - remaining) * 360).toFixed(2)}deg`);
    }

    #schedulePoll() {
        clearTimeout(this.pollTimer);

        let delay = this.expiresAt === null ? THROTTLED_RETRY : POLL_INTERVAL;

        if (this.expiresAt !== null) {
            delay = Math.min(delay, Math.max(0, this.expiresAt - performance.now()) + 250);
        }

        this.pollTimer = setTimeout(() => this.poll(), delay);
    }
}
