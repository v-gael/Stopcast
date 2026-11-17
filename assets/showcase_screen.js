/*
 * Point d'entrée de l'écran de la vitrine (templates/showcase/screen.html.twig) :
 * la page d'affichage et le contrôleur qui la pilote depuis la page d'accueil.
 */
import { Application } from '@hotwired/stimulus';
import DisplayController from './controllers/display_controller.js';
import ShowcaseScreenController from './controllers/showcase_screen_controller.js';

const application = Application.start();
application.register('display', DisplayController);
application.register('showcase-screen', ShowcaseScreenController);
