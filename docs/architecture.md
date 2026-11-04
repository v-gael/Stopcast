# Architecture

## Pourquoi ce projet

Au-delà de l'affichage lui-même (relativement simple), l'essentiel du travail porte sur la modélisation des données GTFS :

- **un arrêt de bus n'est pas un point unique.** Il se compose en général de plusieurs quais, un par sens de circulation — parfois plus de deux sur les pôles d'échange. Le référentiel doit permettre à l'utilisateur de sélectionner précisément *son* quai, sans jamais lui exposer les identifiants techniques (`direction_id`, `stop_id`...) sous-jacents.
- **une ligne peut avoir plusieurs terminus réels** sur un même quai (branches en fin de parcours) : la destination affichée varie donc passage par passage, exactement comme sur un vrai panneau — on ne peut pas la figer à la configuration.
- **le flux temps réel et le référentiel statique doivent provenir de la même source de données**, sous peine de faire correspondre les mauvais identifiants entre les deux (une ligne "1" dans un flux peut très bien être la ligne "4" dans un autre référentiel du même réseau).

## Fonctionnement

```
┌─────────────────────┐      ┌─────────────────┐      ┌───────────────────┐
│   GTFS statique     │      │   GTFS-RT (RT)  │      │   Raspberry Pi    │
│  (import ponctuel,  │      │  (récupéré à    │      │  (script Python,  │
│   CLI Symfony)      │      │   intervalle    │      │   écran 480x320)  │
└──────────┬──────────┘      │   régulier)     │      └──────────┬────────┘
           │                 └─────────┬───────┘                 │
           ▼                           │                         │
   ┌────────────────┐                  │                         │
   │  Base de       │                  │                         │
   │  données       │◄─────────────────┘                         │
   │  (Quay, Route) │                                            │
   └───────┬────────┘                                            │
           │                                                     │
           ▼                                                     │
   ┌───────────────────────────────────┐                         │
   │  API Symfony (endpoint prochains  │◄────────────────────────┘
   │  passages pour un Device donné)   │   GET /api/next-passages
   └───────────────────────────────────┘        (header X-Device-Token)
```

1. **Import du référentiel statique** (`bin/console app:gtfs:import`, ou `make import`), exécuté ponctuellement (à chaque changement d'horaires du réseau). Upsert sur les identifiants naturels GTFS (`stop_id`, `route_id`) pour ne jamais casser les configurations déjà enregistrées.
2. **Enregistrement du dispositif et sélection du/des quai(s)**, une fois, à la configuration initiale — depuis l'espace web, par le propriétaire du dispositif (voir [ADR 0003](adr/0003-front-twig.md)). Le jeton du dispositif n'y est affiché qu'une fois, à sa création ou à sa régénération. Le libellé d'aide au choix (lignes desservies, destinations principales) est calculé à l'import pour rendre le sens de circulation intuitif sans jargon GTFS.
3. **En fonctionnement courant**, le Raspberry Pi interroge uniquement `GET /api/next-passages` avec son token dans le header `X-Device-Token` — pas d'identifiant dans l'URL. L'API résout le dispositif à partir du token, télécharge/parse le flux GTFS-RT une seule fois (mis en cache le temps de `GTFS_REALTIME_CACHE_TTL` secondes, partagé entre tous les devices), et renvoie pour chaque quai configuré (en rotation, dans l'ordre de `position`) les prochains passages (ligne, destination, couleurs, heure attendue), sans les bus qui terminent leur course à ce quai.

## Page d'affichage

Stopcast fait lui-même la mise en page de l'écran, sous forme de page HTML : la page d'affichage. Elle reprend la disposition de l'image dessinée par le client Python du Raspberry Pi (barre du haut avec le nom du quai et l'heure, en-têtes de colonnes, cinq passages par écran avec la pastille de la ligne aux couleurs GTFS, le délai et la destination), ses couleurs et sa police, Roboto Condensed Bold. L'espace web en montre un aperçu ; l'écran l'affichera dans un navigateur en kiosque.

- **Données** : `App\Display\DisplayAssembler` assemble ce qu'un dispositif affiche, une entrée (`DisplayItem`) par configuration active dans l'ordre de rotation. `forDevice()` lit les passages réels du flux temps réel (c'est ce qu'appelle `NextPassagesProvider` pour `/api/next-passages`) ; `sampleForDevice()` et `sampleForConfiguration()` prennent les passages d'exemple de `App\Display\SamplePassages`, fictifs, écrits dans le code et identiques pour toute configuration. `App\Display\ScreenPaginator` découpe ensuite chaque configuration en écrans de cinq passages, sans les passages déjà partis ; une configuration sans passage donne l'écran « aucun passage ».
- **Page** : `templates/display/page.html.twig`, autonome (sans le layout de l'espace web), avec son propre point d'entrée AssetMapper `display` (`assets/display.js`, `assets/styles/display.css`), sans Tailwind ni Turbo. Les écrans sont dans `templates/display/_screens.html.twig`, avec l'écran « aucune configuration active » quand il n'y en a aucun.
- **Mise à l'échelle** : la page remplit la fenêtre, en paysage. Tailles, marges et pastilles se mesurent en `--u`, un 320ᵉ de la hauteur (un 480ᵉ de la largeur si la fenêtre est plus étroite que 3:2) : en 480×320, `--u` vaut un pixel et la page reproduit l'image du client Python. Les colonnes se placent en pourcentage de la largeur : sur un écran plus large, les lignes s'étirent ; sur un écran plus haut, les cinq passages se partagent la hauteur. Les couleurs sont des variables CSS (`--display-background`, `--display-header-background`…).
- **Contrôleur Stimulus** `display` (`assets/controllers/display_controller.js`) : il montre un écran à la fois et passe au suivant après la durée d'écran du dispositif ; il relit les écrans (`?fragment=1`) à la cadence de rafraîchissement, sans recharger la page, et relit à chaque fois les deux cadences. Si un rafraîchissement échoue, les écrans restent affichés et le pictogramme `no_signal` apparaît dans les en-têtes de colonnes jusqu'au prochain rafraîchissement réussi. Il tient l'heure et les délais à jour entre deux rafraîchissements. Les couleurs des lignes passent par le CSSOM : la CSP refuse les attributs `style`.
- **Cadences** : chaque dispositif a une durée d'écran (5 s par défaut, de 3 à 60 s) et un intervalle de rafraîchissement des données (60 s par défaut, de 30 à 300 s), modifiables depuis son formulaire.
- **Aperçu** : `/devices/{id}/preview` (toute la rotation du dispositif) et `/display-configurations/{id}/preview` (une configuration, même masquée) rendent la page sur les passages d'exemple, pour le propriétaire du dispositif seulement (`DeviceVoter`). La page d'un dispositif montre en 480×320 l'aperçu du dispositif, qui fait tourner ses configurations actives comme l'écran ; le formulaire d'une configuration montre le sien. Ces deux routes portent l'option `_frameable` : `SameOriginFramingListener` y remplace l'interdiction d'affichage dans un cadre (`X-Frame-Options`, `frame-ancestors`) par une autorisation limitée au site lui-même.

## Mode worker en prod

En prod, FrankenPHP sert l'appli en mode worker : le kernel Symfony démarre une fois par worker et traite ensuite les requêtes les unes après les autres, au lieu de redémarrer à chaque requête. Le worker est déclaré par `FRANKENPHP_CONFIG` dans la stage `prod` du `Dockerfile`, que lit `frankenphp/Caddyfile` ; `symfony/runtime` passe alors par son `FrankenPhpWorkerRunner`. Chaque worker redémarre après `FRANKENPHP_LOOP_MAX` requêtes (500), ce qui borne une fuite mémoire. Le dev garde le mode classique, où une modification du code est prise en compte sans redémarrage. Le `worker` Messenger est une commande console : il n'est pas concerné.

Les services étant partagés entre les requêtes d'un même worker, la règle est : **pas de donnée de requête dans une propriété d'un service** (utilisateur courant, cache en mémoire, compteur). Un service la reçoit en argument ou la relit (`Security`, `RequestStack`) à chaque appel ; s'il doit vraiment la garder, il implémente `Symfony\Contracts\Service\ResetInterface`, et Symfony le remet à zéro entre deux requêtes. Les services de Symfony et de Doctrine le font déjà (l'`EntityManager` est vidé entre deux requêtes).

La prod locale (`make prod-up`, [developpement.md](developpement.md#environnement-de-prod-local)) tourne en mode worker comme la prod : c'est là qu'une fuite d'état entre deux requêtes se voit, et non en dev ou dans les tests.

## Modèle de données

| Entité | Rôle |
|---|---|
| `Quay` | Un quai GTFS (un sens de circulation précis à un arrêt physique) — import brut du référentiel statique |
| `Route` | Une ligne (numéro, couleurs d'affichage officielles du réseau) |
| `Trip` | Un trajet du référentiel statique, retrouvé par le `trip_id` du flux temps réel et conservé uniquement pour sa destination (`trip_headsign`) et son terminus, où ses passages ne sont pas affichés — voir [ADR 0001](adr/0001-destination-par-trajet.md) et [ADR 0002](adr/0002-terminus-par-trajet.md) |
| `User` | Un compte de l'espace web (email, mot de passe haché, rôle `ROLE_USER` ou `ROLE_ADMIN`) ; en attente tant qu'un invité n'a pas choisi son mot de passe, actif ensuite |
| `Device` | Un dispositif Raspberry Pi, identifié par un jeton d'authentification (`X-Device-Token`), activable/désactivable, qui appartient à un `User` (son propriétaire) |
| `DisplayConfiguration` | La configuration d'affichage d'un `Device` : quel `Quay` afficher, sous quel libellé, dans quel ordre (`position`) en cas de rotation |

## Fuseau horaire

PHP tourne partout en `Europe/Paris`, le fuseau du réseau : il est fixé dans l'image par `frankenphp/conf.d/10-timezone.ini` (dev, CI Docker et prod) et passé à `setup-php` dans les workflows GitHub. Les heures de passage de l'API portent donc le décalage de Paris (`+01:00` en hiver, `+02:00` en été). Les colonnes de date (`createdAt`, `updatedAt`, `deprecatedAt`, `messenger_messages`) sont sans fuseau : elles contiennent l'heure de Paris, ambiguë une heure par an au passage à l'heure d'hiver, ce qui ne touche que des dates d'audit. Un test (`tests/Unit/TimezoneTest.php`) échoue si le réglage disparaît.
