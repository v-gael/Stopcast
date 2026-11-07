/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const HEX_COLOR = /^#[0-9A-Fa-f]{6}$/;

// Panne plus longue : les passages gardés ne sont plus crédibles.
const UNAVAILABLE_AFTER_MS = 15 * 60 * 1000;

/*
 * Page d'affichage d'un dispositif : montre un écran à la fois et passe au
 * suivant après la durée d'écran ; relit les écrans à la cadence de
 * rafraîchissement, sans recharger la page. Les deux cadences (secondes) sont
 * lues sur le contenu à chaque fois : un réglage modifié s'applique au
 * rafraîchissement suivant. Si un rafraîchissement échoue, le contenu reste et
 * « data-display-offline » montre le pictogramme no_signal jusqu'au suivant
 * qui réussit. Un 401 (écran dissocié ou remplacé) recharge la page, qui
 * montre alors l'écran d'appairage.
 *
 * Chaque seconde, les délais sont recomptés depuis l'heure prévue des passages
 * et les passages passés retirés, que le flux réponde ou non. Un écran vidé
 * montre « aucun passage » ; en panne, il montre « Horaires temps réel
 * indisponibles », comme tous les écrans 15 minutes après le dernier
 * rafraîchissement réussi. Le suivant qui réussit rétablit les passages.
 */
export default class extends Controller {
    static targets = ['content', 'screen', 'clock', 'minutes', 'route', 'passage', 'passages', 'noPassage', 'unavailable'];

    static values = { url: String, timeZone: String };

    connect() {
        this.index = 0;
        // Premier chargement en échec : aucun passage reçu, l'écran est déjà « indisponible ».
        this.lastSuccess = this.#offline() ? null : Date.now();
        this.clockFormat = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: this.timeZoneValue || undefined });
        this.#prepare();
        this.clockTimer = setInterval(() => this.#tick(), 1000);
        this.#scheduleRotation();
        this.#scheduleRefresh();
    }

    disconnect() {
        clearInterval(this.clockTimer);
        clearTimeout(this.rotationTimer);
        clearTimeout(this.refreshTimer);
        this.request?.abort();
    }

    async refresh() {
        this.request = new AbortController();

        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'text/html' },
                cache: 'no-store',
                signal: this.request.signal,
            });

            // Cookie de l'écran refusé (dissocié, remplacé) : la page se recharge sur l'écran d'appairage.
            if (response.status === 401) {
                window.location.reload();

                return;
            }

            if (!response.ok) {
                throw new Error(`Réponse ${response.status}`);
            }

            // Une session expirée renvoie vers la page d'accueil : pas d'écrans, c'est un échec.
            const content = new DOMParser()
                .parseFromString(await response.text(), 'text/html')
                .querySelector('[data-display-target="content"]');

            if (!content) {
                throw new Error('Écrans absents de la réponse');
            }

            this.contentTarget.replaceWith(document.adoptNode(content));
            delete this.element.dataset.displayOffline;
            this.lastSuccess = Date.now();
            this.#prepare();
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            this.element.dataset.displayOffline = '';
            this.#tick();
        }

        this.#scheduleRefresh();
    }

    rotate() {
        this.index += 1;
        this.#show();
        this.#scheduleRotation();
    }

    /*
     * Contenu neuf : écran courant gardé s'il existe encore, couleurs des
     * lignes appliquées, heure et délais à jour.
     */
    #prepare() {
        this.#show();
        this.routeTargets.forEach((route) => {
            // La CSP refuse les attributs style : les couleurs passent par le CSSOM.
            for (const [attribute, property] of [['routeColor', '--route-color'], ['routeTextColor', '--route-text-color']]) {
                const color = route.dataset[attribute];

                if (color && HEX_COLOR.test(color)) {
                    route.style.setProperty(property, color);
                }
            }
        });
        this.#tick();
    }

    #show() {
        if (this.index >= this.screenTargets.length) {
            this.index = 0;
        }

        this.screenTargets.forEach((screen, index) => {
            screen.hidden = index !== this.index;
        });
    }

    #tick() {
        const now = Date.now();
        const time = this.clockFormat.format(now);

        this.clockTargets.forEach((clock) => {
            if (clock.textContent !== time) {
                clock.textContent = time;
            }
        });

        this.passageTargets.forEach((passage) => {
            const expectedAt = Number(passage.dataset.expectedAt) * 1000;

            // Passage passé retiré, comme le serveur l'écarte.
            if (expectedAt < now) {
                passage.remove();

                return;
            }

            // Délai recompté entre deux rafraîchissements, arrondi à la minute inférieure comme côté serveur.
            const minutes = passage.querySelector('[data-display-target="minutes"]');
            const text = String(Math.floor((expectedAt - now) / 60000));

            if (minutes && minutes.textContent !== text) {
                minutes.textContent = text;
            }
        });

        this.#showStates(now);
    }

    /*
     * État montré par chaque écran de quai : ses passages, « aucun passage »,
     * ou « indisponible » en panne quand il n'a plus de passage ou que la
     * panne dure. Les écrans d'état (désactivé, aucun quai) n'en ont pas.
     */
    #showStates(now) {
        const offline = this.#offline();
        const expired = offline && (this.lastSuccess === null || now - this.lastSuccess >= UNAVAILABLE_AFTER_MS);

        this.screenTargets.forEach((screen) => {
            const noPassage = screen.querySelector('[data-display-target="noPassage"]');
            const unavailable = screen.querySelector('[data-display-target="unavailable"]');

            if (!noPassage || !unavailable) {
                return;
            }

            const passages = screen.querySelector('[data-display-target="passages"]');
            const hasPassages = Boolean(passages?.querySelector('[data-display-target="passage"]'));
            let shown = hasPassages ? passages : noPassage;

            if (offline && (expired || !hasPassages)) {
                shown = unavailable;
            }

            for (const element of [passages, noPassage, unavailable]) {
                if (element && element.hidden !== (element !== shown)) {
                    element.hidden = element !== shown;
                }
            }
        });
    }

    #offline() {
        return 'displayOffline' in this.element.dataset;
    }

    #scheduleRotation() {
        clearTimeout(this.rotationTimer);
        this.rotationTimer = setTimeout(() => this.rotate(), this.#seconds('screenDuration', 5) * 1000);
    }

    #scheduleRefresh() {
        clearTimeout(this.refreshTimer);
        this.refreshTimer = setTimeout(() => this.refresh(), this.#seconds('refreshInterval', 60) * 1000);
    }

    #seconds(name, fallback) {
        const seconds = Number(this.contentTarget.dataset[name]);

        return Number.isFinite(seconds) && seconds > 0 ? seconds : fallback;
    }
}
