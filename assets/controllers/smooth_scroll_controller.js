import { Controller } from '@hotwired/stimulus';

/*
 * Lien vers une section de la page : défilement doux, sauf si le système
 * demande de réduire les mouvements. L'adresse prend l'ancre, comme un lien
 * ordinaire.
 */
export default class extends Controller {
    go(event) {
        const target = document.getElementById(this.element.hash.slice(1));

        if (!target) {
            return;
        }

        event.preventDefault();
        target.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        history.replaceState(history.state, '', this.element.hash);
    }
}
