/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const DELAY = 5000;

/*
 * Badge « En direct » de la vitrine : le bus approche d'une minute, une seule
 * fois, 5 s après que le bloc est apparu à l'écran.
 */
export default class extends Controller {
    static targets = ['text'];

    static values = { to: String };

    connect() {
        this.observer = new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting) {
                this.observer.disconnect();
                this.timer = setTimeout(() => {
                    this.textTarget.textContent = this.toValue;
                }, DELAY);
            }
        }, { threshold: 0.5 });
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer.disconnect();
        clearTimeout(this.timer);
    }
}
