import { Controller } from '@hotwired/stimulus';

/*
 * Glisser-déposer des quais de la rotation (templates/display_configuration/_rotation.html.twig) :
 * toute la ligne se saisit, les autres s'écartent pour montrer la place libre.
 * À la souris, la saisie part après quelques pixels (un clic reste un clic) ;
 * au doigt, après un appui long, pour que la page défile toujours. Un geste
 * interrompu par le navigateur (pointercancel) remet la ligne à sa place.
 *
 * Au dépôt, la ligne reste où elle est posée et le formulaire d'ordre part par
 * Turbo avec toute la rotation : la réponse met la page à jour par morphing.
 * Sans réponse (réseau coupé), la liste revient à l'ordre d'avant, avec un
 * message.
 *
 * Connecté, il marque la liste [data-rotation-sortable], remis après chaque
 * morphing qui l'efface : les flèches ne restent alors que pour le clavier
 * (app.css). Sans JavaScript, elles restent visibles.
 */
const MOUSE_THRESHOLD = 6;
const TOUCH_HOLD = 300;
const TOUCH_TOLERANCE = 8;
const SHIFT_DURATION = 150;
const NOT_A_GRIP = 'a, button, input, label, select, textarea, [popover]';

export default class extends Controller {
    static targets = ['list', 'item', 'form', 'error'];

    connect() {
        this.mark = () => {
            this.element.dataset.rotationSortable = '';
        };
        this.mark();
        document.addEventListener('turbo:morph', this.mark);

        this.onPointerDown = this.#pointerDown.bind(this);
        this.onTouchMove = (event) => this.drag && event.cancelable && event.preventDefault();
        this.onContextMenu = (event) => (this.drag || this.holdTimer) && event.preventDefault();
        this.listTarget.addEventListener('pointerdown', this.onPointerDown);
        // Non passif : une fois la ligne saisie au doigt, la page ne défile plus.
        this.listTarget.addEventListener('touchmove', this.onTouchMove, { passive: false });
        this.listTarget.addEventListener('contextmenu', this.onContextMenu);
    }

    disconnect() {
        document.removeEventListener('turbo:morph', this.mark);
        this.listTarget.removeEventListener('pointerdown', this.onPointerDown);
        this.listTarget.removeEventListener('touchmove', this.onTouchMove);
        this.listTarget.removeEventListener('contextmenu', this.onContextMenu);
        this.#cancel();
    }

    // Pas de réponse du serveur : la liste revient à l'ordre d'avant le dépôt.
    submitEnd(event) {
        if (event.detail.success || event.detail.fetchResponse || !this.orderBeforeSave) {
            return;
        }

        for (const id of this.orderBeforeSave) {
            this.listTarget.append(this.itemTargets.find((item) => item.dataset.configurationId === id));
        }

        this.errorTarget.hidden = false;
    }

    #pointerDown(event) {
        if (event.button !== 0 || event.target.closest(NOT_A_GRIP) || this.pending) {
            return;
        }

        const item = event.target.closest('[data-rotation-sort-target="item"]');

        if (!item) {
            return;
        }

        const start = { x: event.clientX, y: event.clientY };
        const touch = event.pointerType !== 'mouse';

        this.pending = { item, start, pointerId: event.pointerId, touch };
        this.onMove = this.#pointerMove.bind(this);
        this.onUp = this.#pointerUp.bind(this);
        this.onCancel = this.#cancel.bind(this);
        window.addEventListener('pointermove', this.onMove);
        window.addEventListener('pointerup', this.onUp);
        window.addEventListener('pointercancel', this.onCancel);

        if (touch) {
            this.holdTimer = setTimeout(() => {
                this.holdTimer = null;
                navigator.vibrate?.(10);
                this.#lift();
            }, TOUCH_HOLD);
        }
    }

    #pointerMove(event) {
        if (event.pointerId !== this.pending?.pointerId) {
            return;
        }

        if (!this.drag) {
            const { start, touch } = this.pending;
            const distance = Math.hypot(event.clientX - start.x, event.clientY - start.y);

            if (touch) {
                // Le doigt bouge avant la fin de l'appui : c'est un défilement.
                if (distance > TOUCH_TOLERANCE) {
                    this.#cancel();
                }

                return;
            }

            if (distance < MOUSE_THRESHOLD) {
                return;
            }

            this.#lift();
        }

        this.#follow(event.clientY);
    }

    #pointerUp(event) {
        if (event.pointerId !== this.pending?.pointerId) {
            return;
        }

        const drag = this.drag;

        if (!drag) {
            this.#cancel();

            return;
        }

        drag.slot.replaceWith(drag.item);
        this.#end();
        this.#save(drag.orderBefore);
    }

    // La ligne quitte le flux et suit le pointeur ; une place vide de sa hauteur la remplace.
    #lift() {
        const { item, start } = this.pending;
        const rect = item.getBoundingClientRect();
        const slot = document.createElement('li');

        slot.className = 'rotation-slot';
        slot.style.height = `${rect.height}px`;
        slot.setAttribute('aria-hidden', 'true');
        item.after(slot);

        item.classList.add('rotation-lifted');
        Object.assign(item.style, { left: `${rect.left}px`, top: `${rect.top}px`, width: `${rect.width}px` });

        this.errorTarget.hidden = true;
        this.drag = { item, slot, offset: start.y - rect.top, orderBefore: this.#order() };
    }

    /*
     * La place vide passe devant la première ligne dont le milieu est sous le
     * pointeur ; les autres glissent (FLIP). Les positions viennent d'offsetTop,
     * que les animations en cours (transform) ne faussent pas.
     */
    #follow(pointerY) {
        const { item, slot, offset } = this.drag;
        item.style.top = `${pointerY - offset}px`;

        const listTop = this.listTarget.getBoundingClientRect().top;
        const others = this.itemTargets.filter((each) => each !== item);
        const before = new Map(others.map((each) => [each, each.offsetTop]));
        const next = others.find((each) => pointerY < listTop + each.offsetTop + each.offsetHeight / 2);

        if (next) {
            if (next.previousElementSibling !== slot) {
                next.before(slot);
            }
        } else if (this.listTarget.lastElementChild !== slot) {
            this.listTarget.append(slot);
        }

        for (const each of others) {
            const shift = before.get(each) - each.offsetTop;

            if (shift) {
                each.animate([{ transform: `translateY(${shift}px)` }, { transform: 'none' }], { duration: SHIFT_DURATION, easing: 'ease-out' });
            }
        }
    }

    // Geste abandonné : la ligne, restée à sa place dans la liste, y redescend.
    #cancel() {
        this.drag?.slot.remove();
        this.#end();
    }

    #end() {
        clearTimeout(this.holdTimer);
        this.holdTimer = null;
        window.removeEventListener('pointermove', this.onMove);
        window.removeEventListener('pointerup', this.onUp);
        window.removeEventListener('pointercancel', this.onCancel);

        if (this.drag) {
            this.drag.item.classList.remove('rotation-lifted');
            this.drag.item.removeAttribute('style');
        }

        this.pending = null;
        this.drag = null;
    }

    #save(orderBefore) {
        const order = this.#order();

        if (order.join() === orderBefore.join()) {
            return;
        }

        const form = this.formTarget;
        form.querySelectorAll('input[name="order[]"]').forEach((input) => input.remove());

        for (const id of order) {
            const input = document.createElement('input');
            Object.assign(input, { type: 'hidden', name: 'order[]', value: id });
            form.append(input);
        }

        this.orderBeforeSave = orderBefore;
        form.requestSubmit();
    }

    #order() {
        return this.itemTargets.map((item) => item.dataset.configurationId);
    }
}
