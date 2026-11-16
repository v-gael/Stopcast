import { Controller } from '@hotwired/stimulus';

/*
 * Panneau de connexion de l'en-tête de la vitrine (layout/_login_panel.html.twig),
 * un popover natif. Il s'ouvre seul quand le serveur le demande (« open » : échec
 * de connexion, visiteur renvoyé vers la connexion) et ne crée le widget
 * Turnstile qu'à la première ouverture : les pages publiques ne chargent pas le
 * script de Cloudflare pour un visiteur qui ne se connecte pas.
 */
export default class extends Controller {
    static targets = ['captcha'];

    static values = { open: Boolean };

    connect() {
        if (this.openValue && !this.element.matches(':popover-open')) {
            this.element.showPopover();
        }

        this.beforeCache = this.#resetForCache.bind(this);
        document.addEventListener('turbo:before-cache', this.beforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.beforeCache);
    }

    toggled(event) {
        if (event.newState !== 'open' || !this.hasCaptchaTarget) {
            return;
        }

        const controllers = this.captchaTarget.dataset.controller?.split(' ') ?? [];

        if (!controllers.includes('turnstile')) {
            this.captchaTarget.dataset.controller = [...controllers, 'turnstile'].join(' ');
        }
    }

    // La page gardée par Turbo (retour arrière) revient panneau fermé, sans widget ni réouverture.
    #resetForCache() {
        if (this.element.matches(':popover-open')) {
            this.element.hidePopover();
        }

        this.openValue = false;

        if (this.hasCaptchaTarget) {
            const controllers = (this.captchaTarget.dataset.controller ?? '').split(' ').filter((name) => name && name !== 'turnstile');
            this.captchaTarget.dataset.controller = controllers.join(' ');
        }
    }
}
