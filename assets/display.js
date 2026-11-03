/*
 * Point d'entrée de la page d'affichage (templates/display/page.html.twig) :
 * ses styles et son seul contrôleur, sans Turbo ni ceux de l'espace web.
 */
import { Application } from '@hotwired/stimulus';
import DisplayController from './controllers/display_controller.js';
import './styles/display.css';

Application.start().register('display', DisplayController);
