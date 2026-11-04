/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const HEX_COLOR = /^#[0-9A-Fa-f]{6}$/;

/*
 * Page d'affichage d'un dispositif : montre un écran à la fois et passe au
 * suivant après la durée d'écran ; relit les écrans à la cadence de
 * rafraîchissement, sans recharger la page. Les deux cadences (secondes) sont
 * lues sur le contenu à chaque fois : un réglage modifié s'applique au
 * rafraîchissement suivant. Si un rafraîchissement échoue, le contenu reste et
 * « data-display-offline » montre le pictogramme no_signal jusqu'au suivant
 * qui réussit. Un 401 (écran dissocié ou remplacé) recharge la page, qui
 * montre alors l'écran d'appairage.
 */
export default class extends Controller {
    static targets = ['content', 'screen', 'clock', 'minutes', 'route'];

    static values = { url: String, timeZone: String };

    connect() {
        this.index = 0;
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
            this.#prepare();
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            this.element.dataset.displayOffline = '';
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

        // Délais recomptés entre deux rafraîchissements, arrondis à la minute inférieure comme côté serveur.
        this.minutesTargets.forEach((minutes) => {
            const text = String(Math.max(0, Math.floor((Number(minutes.dataset.expectedAt) * 1000 - now) / 60000)));

            if (minutes.textContent !== text) {
                minutes.textContent = text;
            }
        });
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
