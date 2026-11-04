/*
 * Point d'entrée de la page d'affichage (templates/display/page.html.twig) :
 * son seul contrôleur, sans Turbo ni ceux de l'espace web. Ses styles sont
 * liés par la page : la CSP bloque une CSS importée en JavaScript.
 */
import { Application } from '@hotwired/stimulus';
import DisplayController from './controllers/display_controller.js';

Application.start().register('display', DisplayController);
