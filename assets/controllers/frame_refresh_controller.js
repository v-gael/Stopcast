import { Controller } from '@hotwired/stimulus';

/*
 * Recharge le turbo-frame qui contient l'élément, quelques secondes plus tard.
 * Le serveur ne pose ce contrôleur que tant que ce qu'il affiche va changer
 * (fiche d'un dispositif en cours d'appairage) : le frame rechargé le reprend
 * s'il le faut encore, et les rechargements s'arrêtent d'eux-mêmes sinon.
 */
const RELOAD_DELAY = 3000;

export default class extends Controller {
    connect() {
        this.timer = setTimeout(() => this.#reload(), RELOAD_DELAY);
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    #reload() {
        const frame = this.element.closest('turbo-frame');

        if (!frame) {
            return;
        }

        // Le premier chargement vient de la source posée, les suivants de reload().
        if (frame.src) {
            frame.reload();
        } else {
            frame.src = window.location.href;
        }
    }
}
