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
│  (import ponctuel,  │      │  (récupéré à    │      │  (navigateur en   │
│   CLI Symfony)      │      │   intervalle    │      │  kiosque, écran   │
└──────────┬──────────┘      │   régulier)     │      │  480x320)         │
           │                 └─────────┬───────┘      └──────────┬────────┘
           ▼                           │                         │
   ┌────────────────┐                  │                         │
   │  Base de       │                  │                         │
   │  données       │◄─────────────────┘                         │
   │  (Quay, Route) │                                            │
   └───────┬────────┘                                            │
           │                                                     │
           ▼                                                     │
   ┌───────────────────────────────────┐                         │
   │  Page d'affichage Symfony         │◄────────────────────────┘
   │  (prochains passages du Device)   │   GET /display
   └───────────────────────────────────┘   (cookie de l'écran appairé)
```

1. **Import du référentiel statique** (`bin/console app:gtfs:import`, ou `make import`), exécuté ponctuellement (à chaque changement d'horaires du réseau). Upsert sur les identifiants naturels GTFS (`stop_id`, `route_id`) pour ne jamais casser les configurations déjà enregistrées.
2. **Enregistrement du dispositif et sélection du/des quai(s)**, une fois, à la configuration initiale — depuis l'espace web, par le propriétaire du dispositif (voir [ADR 0003](adr/0003-front-twig.md)). Son écran y est appairé par un code (voir [Appairage d'un écran](#appairage-dun-écran)). Le libellé d'aide au choix (lignes desservies, destinations principales) est calculé à l'import pour rendre le sens de circulation intuitif sans jargon GTFS.
3. **En fonctionnement courant**, le navigateur du Raspberry Pi ouvre `/display`, authentifié par le cookie de son écran appairé — pas d'identifiant dans l'URL — puis relit les écrans (`/display?fragment=1`) à la cadence du dispositif. Stopcast résout le dispositif à partir du cookie, télécharge/parse le flux GTFS-RT une seule fois (mis en cache le temps de `GTFS_REALTIME_CACHE_TTL` secondes, partagé entre tous les dispositifs), et rend pour chaque quai configuré (en rotation, dans l'ordre de `position`) les prochains passages (ligne, destination, couleurs, délai), sans les bus qui terminent leur course à ce quai.

## Page d'affichage

Stopcast fait lui-même la mise en page de l'écran, sous forme de page HTML : la page d'affichage. Elle reprend la disposition de l'image dessinée par le client Python du Raspberry Pi (barre du haut avec le nom du quai et l'heure, en-têtes de colonnes, cinq passages par écran avec la pastille de la ligne aux couleurs GTFS, le délai et la destination), ses couleurs et sa police, Roboto Condensed Bold. L'espace web en montre un aperçu ; l'écran l'affiche sur ses vraies données dans un navigateur en kiosque, sur `/display`, après un appairage par code (voir [ADR 0008](adr/0008-affichage-html-et-appairage.md)).

- **Données** : `App\Display\DisplayAssembler` assemble ce qu'un dispositif affiche, une entrée (`DisplayItem`) par configuration active dans l'ordre de rotation. `forDevice()` lit les passages réels du flux temps réel (c'est ce qu'appelle `DisplayController` pour `/display`) ; `sampleForDevice()` et `sampleForConfiguration()` prennent les passages d'exemple de `App\Display\SamplePassages`, fictifs, écrits dans le code et identiques pour toute configuration. `App\Display\ScreenPaginator` découpe ensuite chaque configuration en écrans de cinq passages, sans les passages déjà partis ; une configuration sans passage donne l'écran « aucun passage ».
- **Page** : `templates/display/page.html.twig`, autonome (sans le layout de l'espace web), avec son propre point d'entrée AssetMapper `display` (`assets/display.js`, `assets/styles/display.css`), sans Tailwind ni Turbo. Les écrans sont dans `templates/display/_screens.html.twig`, avec l'écran « aucun quai affiché » quand aucune configuration n'est active.
- **Mise à l'échelle** : la page remplit la fenêtre, en paysage. Tailles, marges et pastilles se mesurent en `--u`, un 320ᵉ de la hauteur (un 480ᵉ de la largeur si la fenêtre est plus étroite que 3:2) : en 480×320, `--u` vaut un pixel et la page reproduit l'image du client Python. Les colonnes se placent en pourcentage de la largeur : sur un écran plus large, les lignes s'étirent ; sur un écran plus haut, les cinq passages se partagent la hauteur. Les couleurs sont des variables CSS (`--display-background`, `--display-header-background`…).
- **Contrôleur Stimulus** `display` (`assets/controllers/display_controller.js`) : il montre un écran à la fois et passe au suivant après la durée d'écran du dispositif ; il relit les écrans (`?fragment=1`) à la cadence de rafraîchissement, sans recharger la page, et relit à chaque fois les deux cadences. Si un rafraîchissement échoue, les écrans restent affichés et le pictogramme `no_signal` apparaît dans les en-têtes de colonnes jusqu'au prochain rafraîchissement réussi ; un 401 (écran dissocié ou remplacé) recharge la page, qui montre alors l'écran d'appairage. Il tient l'heure et les délais à jour entre deux rafraîchissements. Les couleurs des lignes passent par le CSSOM : la CSP refuse les attributs `style`.
- **Cadences** : chaque dispositif a une durée d'écran (5 s par défaut, de 3 à 60 s) et un intervalle de rafraîchissement des données (60 s par défaut, de 30 à 300 s), modifiables depuis son formulaire.
- **Aperçu** : `/devices/{id}/preview` (toute la rotation du dispositif) et `/display-configurations/{id}/preview` (une configuration, même masquée) rendent la page sur les passages d'exemple, pour le propriétaire du dispositif seulement (`DeviceVoter`). La liste « Mes dispositifs » montre l'aperçu de chaque dispositif dans sa carte (grisé s'il est inactif), et la page d'un dispositif le montre en 480×320 ; il fait tourner les configurations actives comme l'écran ; le formulaire d'une configuration montre le sien. Ces deux routes portent l'option `_frameable` : `SameOriginFramingListener` y remplace l'interdiction d'affichage dans un cadre (`X-Frame-Options`, `frame-ancestors`) par une autorisation limitée au site lui-même.
- **Page sur l'écran** : `App\Controller\DisplayController` sert `/display` à l'écran authentifié par son cookie (firewall `display`, voir plus bas) : les écrans de ses configurations actives sur les passages réels ; l'écran d'état « Écran désactivé » si le dispositif est désactivé (le cookie est gardé, la page repart seule à la réactivation). Si le flux temps réel échoue, le fragment répond 503 et l'écran garde ce qu'il affichait ; au premier chargement, la page s'ouvre sans passages, avec `no_signal`, et réessaie à la cadence du dispositif.

## Appairage d'un écran

L'écran s'authentifie par un cookie, obtenu en affichant un code que le propriétaire saisit (voir [ADR 0008](adr/0008-affichage-html-et-appairage.md)).

- **Écran d'appairage** : `/display` sans cookie de dispositif rend `templates/display/pairing.html.twig` : à gauche le QR code et le code (`K7F-3QX`, six caractères sans `0/O`, `1/I/L`), à droite un compte à rebours en camembert, en bas une ligne d'instruction. Le contrôleur Stimulus `pairing` interroge `/display/pairing` toutes les 5 s (et dès que le code expire) : 200 avec le code à afficher, 429 quand trop de codes ont été demandés depuis cette adresse, 205 quand l'écran est appairé ; la page se recharge alors sur l'affichage.
- **Demandes** : `App\Display\Pairing\ScreenPairing` garde les demandes dans le pool de cache `display_pairing.cache`, sous le haché d'un secret aléatoire que l'écran garde dans un cookie temporaire (`STOPCAST_DISPLAY_PAIRING`, le temps de la session du navigateur) ; un rechargement reprend la même demande. Un code est affiché une minute, puis remplacé ; il reste accepté une minute de plus. Les durées sont vérifiées avec l'horloge (`ClockInterface`).
- **QR code** : au-dessus du code, l'écran affiche un QR code (`endroid/qr-code`, rendu en SVG inline par `App\Display\Pairing\QrCodeSvg`) vers l'URL absolue `/devices/pair?code=K7F3QX`, construite sur l'hôte de la requête de l'écran. En prod, c'est le domaine public ; en dev, derrière le tunnel du Pi, c'est `localhost:8080`, que le téléphone ne joint pas. Son libellé accessible porte l'URL encodée.
- **Saisie** : `/devices/{id}/pair`, sur la fiche du dispositif (« Appairer un écran », ou « Remplacer l'écran »), ou `/devices/pair?code=…`, ouverte en scannant le QR code : après la connexion si besoin, le propriétaire choisit un de ses dispositifs (présélectionné s'il est seul) et appaire. Le code n'y est vérifié qu'à l'envoi : ouvrir l'adresse n'apprend rien sur sa validité. Sans dispositif, « Créer un dispositif » mène à `/devices/new?pair=K7F3QX`, qui revient sur la page avec le nouveau dispositif coché ; `pair` ne porte qu'un code validé par `PairingCode::normalize()`, jamais une adresse de retour. Les deux parcours passent par `ScreenPairing::confirm()` et partagent la limite de saisies fausses par compte. Le code sert une fois. À l'interrogation suivante, l'écran reçoit son cookie (`STOPCAST_DISPLAY`, un secret de 256 bits), dont seul le haché SHA-256 est enregistré sur `Device`. « Dissocier » (`/devices/{id}/unpair`) efface le haché.
- **Firewall `display`** (`^/display(/|$)`, stateless) : `access_token` avec `ScreenCookieExtractor` et `ScreenCookieHandler`, qui retrouve le dispositif par le haché et l'enveloppe dans un `DeviceUser`. Un cookie que plus aucun dispositif ne reconnaît est effacé par `ScreenCookieRejectedHandler` : redirection de la page vers elle-même, 401 pour un rafraîchissement ou une interrogation. Sans cookie, l'écran passe en anonyme. `ScreenCookieRenewalListener` renvoie le cookie, 400 jours de plus, sur chaque réponse authentifiée.
- **En dev**, les routes de l'écran portent l'option `_without_toolbar` : la barre de debug ne cache pas le bas de l'affichage.

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
| `Device` | Un dispositif d'affichage, activable/désactivable, qui appartient à un `User` (son propriétaire) ; son écran appairé s'authentifie par un cookie dont le haché est enregistré sur le dispositif |
| `DisplayConfiguration` | La configuration d'affichage d'un `Device` : quel `Quay` afficher, sous quel libellé, dans quel ordre (`position`) en cas de rotation |

Chaque suppression de compte (par son titulaire, un administrateur ou la purge) est d'abord inscrite dans un journal hors base, `var/account-deletions/deletions.jsonl` (`DeletionJournal`) : l'identifiant et la date de création du compte, sans email. Après la restauration d'une sauvegarde, `app:account:replay-deletions` supprime de nouveau ces comptes, pour qu'ils ne reviennent pas (droit à l'effacement). Le journal garde 30 jours, la durée des sauvegardes ; `app:purge` retire les lignes plus anciennes.

## Fuseau horaire

PHP tourne partout en `Europe/Paris`, le fuseau du réseau : il est fixé dans l'image par `frankenphp/conf.d/10-timezone.ini` (dev, CI Docker et prod) et passé à `setup-php` dans les workflows GitHub. L'horloge de la page d'affichage suit donc l'heure de Paris, quel que soit le fuseau du navigateur de l'écran (`data-display-time-zone-value`) ; les délais se calculent sur des instants, sans fuseau. Les colonnes de date (`createdAt`, `updatedAt`, `deprecatedAt`, `messenger_messages`) sont sans fuseau : elles contiennent l'heure de Paris, ambiguë une heure par an au passage à l'heure d'hiver, ce qui ne touche que des dates d'audit. Un test (`tests/Unit/TimezoneTest.php`) échoue si le réglage disparaît.
