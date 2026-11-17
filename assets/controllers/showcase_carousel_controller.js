/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const INTERVAL = 3000;

/*
 * « L'écran que vous avez déjà » : un appareil à la fois, avec sa légende.
 */
export default class extends Controller {
    static targets = ['item', 'caption'];

    connect() {
        // Page restaurée du cache de Turbo : elle peut montrer un autre appareil.
        this.index = -1;
        this.#next();
        this.timer = setInterval(() => this.#next(), INTERVAL);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    #next() {
        this.index = (this.index + 1) % this.itemTargets.length;
        this.itemTargets.forEach((item, position) => item.classList.toggle('opacity-0', position !== this.index));
        this.captionTarget.textContent = this.itemTargets[this.index].dataset.caption;
    }
}
