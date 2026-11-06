import { Controller } from '@hotwired/stimulus';

/*
 * Widget Cloudflare Turnstile (composant Turnstile). Le script de Cloudflare
 * n'est chargé que sur les pages qui portent le widget, une seule fois même
 * après plusieurs visites Turbo, en rendu explicite : le widget est affiché à
 * l'arrivée du formulaire et retiré à son départ. À l'expiration de son jeton
 * (300 s), il en obtient un nouveau.
 */

// Nonce de la CSP de la première page, lu au chargement du module : après une
// visite Turbo, le document garde la CSP de cette page, qui refuserait le
// nonce de la nouvelle.
const nonce = document.querySelector('meta[name="csp-nonce"]')?.content;

let api;

function loadApi() {
    api ??= new Promise((resolve, reject) => {
        window.onTurnstileLoad = () => {
            delete window.onTurnstileLoad;
            resolve(window.turnstile);
        };

        const script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=onTurnstileLoad';
        script.async = true;
        if (nonce) {
            script.nonce = nonce;
        }
        script.addEventListener('error', () => {
            // Permet un nouvel essai à la prochaine page qui porte le widget.
            api = undefined;
            script.remove();
            reject(new Error('Turnstile could not be loaded.'));
        });
        document.head.append(script);
    });

    return api;
}

export default class extends Controller {
    static values = { siteKey: String };

    async connect() {
        const turnstile = await loadApi();

        // Le formulaire a pu partir pendant le chargement du script.
        if (!this.element.isConnected || this.widgetId !== undefined) {
            return;
        }

        // Une page restaurée du cache de Turbo garde le widget d'avant, inerte.
        this.element.replaceChildren();
        this.widgetId = turnstile.render(this.element, {
            sitekey: this.siteKeyValue,
            theme: 'light',
            language: 'fr',
            size: 'flexible',
            appearance: 'always',
            'expired-callback': () => turnstile.reset(this.widgetId),
        });
    }

    disconnect() {
        if (this.widgetId !== undefined) {
            window.turnstile?.remove(this.widgetId);
            this.widgetId = undefined;
        }
    }
}
