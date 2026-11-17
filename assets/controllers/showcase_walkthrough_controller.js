/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const PRESS_MS = 180;
const FLY_MS = 900;

// Saisie lettre à lettre : un temps par lettre.
const typing = (field, text, ms) => [...text].map((_, length) => ({ fields: { [field]: text.slice(0, length + 1) }, focus: field, ms }));

const IDLE = { screen: 'idle' };

/*
 * Le parcours, étape par étape : des temps qui changent la maquette du site
 * (page montrée, champs, ce qui dépend de l'avancée) et l'écran, puis
 * attendent un délai ou le clic du visiteur sur le bouton qui brille (go).
 */
const steps = (address) => [
    [
        { page: '/devices', screen: IDLE, go: 'add-device' },
        { page: '/devices/new', focus: 'device-label', ms: 400 },
        ...typing('device-label', 'Maison', 110),
        { focus: null, go: 'create' },
        { page: '/devices/12', ms: 900 },
    ],
    [
        { page: '/devices/12', screen: IDLE, go: 'add-quay' },
        { page: '/display-configurations/new', fields: { quay: '', 'quay-label': '' }, focus: 'quay', ms: 300 },
        ...typing('quay', 'Beurn', 140),
        { on: ['options-beurnonville'], ms: 900 },
        { off: ['options-beurnonville'], pick: 'options-beurnonville', focus: null, go: 'add' },
        { page: '/devices/12', on: ['quay-1'], go: 'add-quay' },
        { page: '/display-configurations/new', fields: { quay: '', 'quay-label': '' }, focus: 'quay', ms: 300 },
        ...typing('quay', 'Patt', 140),
        { on: ['options-patton'], ms: 900 },
        { off: ['options-patton'], pick: 'options-patton', focus: 'quay-label', ms: 300 },
        ...typing('quay-label', 'Direction travail', 55),
        { focus: null, go: 'add' },
        { page: '/devices/12', on: ['quay-2'], ms: 900 },
    ],
    [
        { page: '/devices/12', screen: IDLE, go: 'pair' },
        { page: '/devices/12/pair', go: 'copy' },
        { on: ['copied'], fly: true },
        ...[0.05, 0.4, 0.65, 0.85, 1].map((part) => ({ screen: { screen: 'url', text: address.slice(0, Math.ceil(address.length * part)) }, ms: 160 })),
        { screen: { screen: 'pairing' }, ms: 1300 },
    ],
    [
        { page: '/devices/12/pair', screen: { screen: 'pairing' }, focus: 'code', ms: 300 },
        ...['K', 'K7', 'K7F', 'K7F-3', 'K7F-3Q', 'K7F-3QX'].map((code) => ({ fields: { code }, focus: 'code', ms: 170 })),
        { focus: null, go: 'confirm' },
        { page: '/devices/12', on: ['screen-paired', 'pairing'], ms: 1400 },
        { off: ['pairing'], on: ['online'], screen: { screen: 'display' }, ms: 1800 },
        { page: '/devices', on: ['paired'] },
    ],
];

/*
 * « Comment ça marche » de la vitrine (templates/home/_walkthrough.html.twig) :
 * le parcours démarre à l'étape 1 et attend le visiteur à chaque bouton qui
 * brille. Une étape cliquée reprend le parcours à son début, avec le site dans
 * l'état où les étapes précédentes l'ont laissé ; « Recommencer » repart de la
 * première. L'écran du parcours reçoit ses commandes par postMessage
 * (showcase_screen_controller.js).
 */
export default class extends Controller {
    static targets = ['step', 'caption', 'page', 'path', 'address', 'screen'];

    static values = { done: String };

    connect() {
        this.steps = steps(this.addressTarget.textContent.trim());
        this.run = 0;
        this.glowing = null;
        this.following = false;
        this.sentScreen = null;
        this.frame = this.screenTarget.querySelector('iframe');
        // Écran pas encore chargé, ou rechargé : il reçoit l'état courant.
        this.onFrameLoad = () => this.#send(true);
        this.frame.addEventListener('load', this.onFrameLoad);
        this.#play(0);
    }

    disconnect() {
        this.run += 1;
        this.#cancel?.();
        this.frame.removeEventListener('load', this.onFrameLoad);
    }

    restart() {
        this.#play(0);
    }

    jump({ currentTarget }) {
        this.#play(this.stepTargets.indexOf(currentTarget));
    }

    press(event) {
        const button = event.target.closest('[data-go]');

        if (button && button === this.glowing) {
            this.#resume?.();
        }
    }

    #cancel = null;

    #resume = null;

    async #play(from) {
        this.run += 1;
        this.#cancel?.();
        const run = this.run;

        // État laissé par les étapes précédentes, sans les jouer.
        this.state = { page: '/devices', fields: {}, on: new Set(), focus: null, screen: IDLE };
        this.steps.slice(0, from).flat().forEach((beat) => this.#apply(beat));

        for (let index = from; index < this.steps.length; index += 1) {
            this.#showStep(index, this.stepTargets[index].dataset.caption);

            for (const beat of this.steps[index]) {
                this.#apply(beat);
                this.#render(beat.go ?? null);

                if (beat.fly) {
                    await this.#fly();
                }

                if (beat.go) {
                    await this.#click();
                } else if (beat.ms) {
                    await this.#sleep(beat.ms);
                }

                if (run !== this.run) {
                    return;
                }
            }
        }

        this.#showStep(this.steps.length, this.doneValue);
        this.#render(null);
    }

    #apply(beat) {
        const { state } = this;

        state.page = beat.page ?? state.page;
        Object.assign(state.fields, beat.fields);
        beat.on?.forEach((name) => state.on.add(name));
        beat.off?.forEach((name) => state.on.delete(name));
        state.focus = beat.focus === undefined ? state.focus : beat.focus;
        state.screen = beat.screen ?? state.screen;

        if (beat.pick) {
            const option = this.element.querySelector(`[data-when="${beat.pick}"] [data-option]`);
            state.fields.quay = option.textContent.replace(/\s+/g, ' ').trim();
        }
    }

    #render(go) {
        const { state } = this;

        this.pageTargets.forEach((page) => page.classList.toggle('is-on', page.dataset.path === state.page));
        this.pathTarget.textContent = state.page;
        this.element.querySelectorAll('[data-field]').forEach((field) => {
            field.textContent = state.fields[field.dataset.field] ?? '';
        });
        this.element.querySelectorAll('[data-field-box]').forEach((box) => box.classList.toggle('is-focused', box.dataset.fieldBox === state.focus));
        this.element.querySelectorAll('[data-when]').forEach((element) => element.classList.toggle('is-on', state.on.has(element.dataset.when)));
        this.element.querySelectorAll('[data-unless]').forEach((element) => element.classList.toggle('is-off', state.on.has(element.dataset.unless)));

        // Le focus clavier suit le bouton qui brille ; entre deux, il attend sur le parcours (tabindex="-1"),
        // la page du bouton cliqué venant peut-être d'être cachée.
        const follow = Boolean(this.glowing?.contains(document.activeElement)) || (this.following && document.activeElement === this.element);

        this.glowing?.classList.remove('showcase-glow', 'showcase-press');
        this.glowing?.setAttribute('tabindex', '-1');
        this.glowing = go ? this.element.querySelector(`[data-path="${state.page}"] [data-go="${go}"]`) : null;
        this.glowing?.classList.add('showcase-glow');
        this.glowing?.setAttribute('tabindex', '0');

        this.following = follow;

        if (follow) {
            (this.glowing ?? this.element).focus({ preventScroll: true });
        }

        this.#send(false);
    }

    #showStep(index, caption) {
        this.stepTargets.forEach((step, position) => {
            step.classList.toggle('is-on', position === index);
            step.classList.toggle('is-done', position < index);

            if (position === index) {
                step.setAttribute('aria-current', 'step');
            } else {
                step.removeAttribute('aria-current');
            }
        });
        this.captionTarget.textContent = caption;
    }

    // Commande à l'écran, s'il ne l'a pas déjà ; de la même origine seulement.
    #send(force) {
        const command = JSON.stringify(this.state.screen);

        if (force || command !== this.sentScreen) {
            this.sentScreen = command;
            this.frame.contentWindow?.postMessage(this.state.screen, window.location.origin);
        }
    }

    #click() {
        return new Promise((resolve) => {
            this.#resume = () => {
                this.glowing?.classList.add('showcase-press');
                setTimeout(resolve, PRESS_MS);
            };
            this.#cancel = resolve;
        });
    }

    #sleep(ms) {
        return new Promise((resolve) => {
            const timer = setTimeout(resolve, ms);
            this.#cancel = () => {
                clearTimeout(timer);
                resolve();
            };
        });
    }

    // L'adresse copiée part du site vers l'écran.
    async #fly() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const source = this.addressTarget;
        const from = source.getBoundingClientRect();
        const to = this.screenTarget.getBoundingClientRect();
        const ghost = source.cloneNode(true);

        ghost.removeAttribute('data-showcase-walkthrough-target');
        ghost.setAttribute('aria-hidden', 'true');
        Object.assign(ghost.style, { position: 'fixed', left: `${from.left}px`, top: `${from.top}px`, zIndex: 40, margin: 0, boxShadow: '0 10px 25px -5px #0005' });
        document.body.append(ghost);

        try {
            await ghost.animate(
                [{ transform: 'translate(0, 0)' }, { transform: `translate(${to.left + 24 - from.left}px, ${to.top + 18 - from.top}px)`, opacity: 0.9 }],
                { duration: FLY_MS, easing: 'cubic-bezier(.5, 0, .2, 1)' },
            ).finished;
        } finally {
            ghost.remove();
        }
    }
}
