/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Écran de la vitrine, dans un cadre de la page d'accueil. Il obéit aux
 * commandes que la page lui envoie par postMessage, de la même origine
 * seulement : { screen: 'idle' | 'pairing' | 'display' }, ou
 * { screen: 'url', text } pour l'adresse en cours de saisie. 'display' lance
 * la rotation depuis le premier écran.
 *
 * Il annonce à la page chaque écran montré ({ shown: index }), pour qu'elle
 * suive la rotation.
 */
export default class extends Controller {
    static targets = ['panel', 'url'];

    connect() {
        this.onMessage = (event) => this.#receive(event);
        this.onShown = (event) => window.parent.postMessage({ shown: event.detail.index }, window.location.origin);
        window.addEventListener('message', this.onMessage);
        this.element.addEventListener('display:shown', this.onShown);
    }

    disconnect() {
        window.removeEventListener('message', this.onMessage);
        this.element.removeEventListener('display:shown', this.onShown);
        cancelAnimationFrame(this.frame);
    }

    #receive({ origin, source, data }) {
        if (origin !== window.location.origin || source !== window.parent || typeof data?.screen !== 'string') {
            return;
        }

        cancelAnimationFrame(this.frame);
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.panel !== data.screen;
        });

        if (data.screen === 'url') {
            this.urlTarget.textContent = String(data.text ?? '');
        }

        if (data.screen === 'pairing') {
            this.#countDown();
        }

        if (data.screen === 'display') {
            this.application.getControllerForElementAndIdentifier(this.#panel('display'), 'display')?.play();
        }
    }

    /*
     * Le disque du compte à rebours se retire sur une minute, comme sur un
     * vrai écran d'appairage (pairing_controller.js).
     */
    #countDown() {
        const countdown = this.#panel('pairing').querySelector('.pairing__countdown');
        const start = performance.now();
        const step = (time) => {
            countdown?.style.setProperty('--elapsed', `${Math.min(360, ((time - start) / 60000) * 360)}deg`);
            this.frame = requestAnimationFrame(step);
        };

        this.frame = requestAnimationFrame(step);
    }

    #panel(name) {
        return this.panelTargets.find((panel) => panel.dataset.panel === name);
    }
}
