/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * « Plusieurs arrêts sur un écran » : le nom de l'arrêt affiché s'allume, et
 * la barre se remplit pendant la durée d'un écran. L'écran de la vitrine
 * annonce chaque changement (showcase_screen_controller.js).
 */
export default class extends Controller {
    static targets = ['frame', 'stop', 'bar'];

    static values = { duration: { type: Number, default: 4 } };

    connect() {
        this.onMessage = ({ origin, source, data }) => {
            const frame = this.frameTarget.querySelector('iframe');

            if (origin === window.location.origin && source === frame?.contentWindow && Number.isInteger(data?.shown)) {
                this.#show(data.shown);
            }
        };
        window.addEventListener('message', this.onMessage);
    }

    disconnect() {
        window.removeEventListener('message', this.onMessage);
        cancelAnimationFrame(this.frame);
    }

    #show(index) {
        this.stopTargets.forEach((stop, position) => stop.classList.toggle('is-shown', position === index));

        // La CSP refuse les attributs style, pas le CSSOM.
        const bar = this.barTarget;
        bar.style.transition = 'none';
        bar.style.width = '0';
        cancelAnimationFrame(this.frame);
        this.frame = requestAnimationFrame(() => {
            this.frame = requestAnimationFrame(() => {
                bar.style.transition = `width ${this.durationValue - 0.1}s linear`;
                bar.style.width = '100%';
            });
        });
    }
}
