/*
 * Point d'entrée de la page d'affichage et de l'écran d'appairage
 * (templates/display/) : leurs contrôleurs, sans Turbo ni ceux de l'espace web. Ses styles sont
 * liés par la page : la CSP bloque une CSS importée en JavaScript.
 */
import { Application } from '@hotwired/stimulus';
import DisplayController from './controllers/display_controller.js';
import PairingController from './controllers/pairing_controller.js';

const application = Application.start();
application.register('display', DisplayController);
application.register('pairing', PairingController);
