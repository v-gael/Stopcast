# Front d'administration en Twig, API Platform réservé au dispositif

L'administration passait par l'API (`/api/devices`, `/api/display_configurations`), protégée par une clé unique : gérer ses dispositifs demandait curl et cette clé. Le projet a besoin d'une interface web où plusieurs personnes gèrent chacune leurs propres dispositifs.

Nous construisons ce front en **rendu serveur** : Twig, le composant Form et Turbo, stylé avec Tailwind (`symfonycasts/tailwind-bundle`, sans Node) et des Twig Components pour les éléments communs (bouton, carte, badge, message flash). Les écrans vivent dans la même application Symfony que l'API, derrière une connexion par formulaire et des sessions.

API Platform ne sert plus que le dispositif : `GET /api/next-passages`, authentifié par le jeton du dispositif. Les ressources d'administration de l'API (`Device`, `DisplayConfiguration`, `Quay`, `Route`) et la clé d'administration disparaissent.

## Options écartées

- **API Platform Admin** (React Admin généré depuis l'API) : rapide pour un CRUD brut, mais il faut une authentification par jeton côté navigateur, une ressource par écran, et la personnalisation (choix du quai avec ses destinations desservies, ordre des configurations, invitations) se fait en React, hors de la stack du projet.
- **SPA React** : un deuxième projet à construire, tester et déployer (build Node, routage, gestion des jetons), pour une poignée de formulaires. L'API d'administration aurait dû rester et grossir (comptes, invitations).
- **Next.js** : mêmes coûts que React, plus un serveur Node à héberger à côté de FrankenPHP.

## Conséquences

- Les formulaires, la validation, la sécurité (connexion, voters, CSRF) et les emails passent par les composants Symfony, testés en `WebTestCase`.
- Pas de build Node : Tailwind est compilé par son binaire autonome (`tailwind:build`), les assets servis par AssetMapper. L'image de prod compile la CSS et les assets au build.
- L'API publique se réduit au contrat du dispositif, documenté par OpenAPI ; toute évolution de l'administration se fait côté Twig.
