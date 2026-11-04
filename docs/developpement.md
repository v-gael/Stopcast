# Développement

## Prérequis

- Docker et Docker Compose
- `make`

L'application tourne dans un conteneur FrankenPHP (PHP 8.5), avec une base PostgreSQL 16. La version minimale de PHP, correctifs de sécurité compris, est fixée dans `composer.json` : Composer refuse de s'installer et l'application de démarrer sur une version plus ancienne. `make build` retélécharge l'image de base pour récupérer le dernier correctif.

## Démarrage

```bash
cp .env .env.local           # puis renseigner les URLs GTFS (voir docs/api.md)
make up                      # démarre les conteneurs de dev et active le hook pre-push
make migrate                 # applique les migrations
make import                  # importe le référentiel GTFS statique
make fixtures                # (optionnel) crée un compte admin et des dispositifs de démo (voir plus bas)
```

La vitrine, avec le formulaire de connexion, est ensuite disponible sur `http://localhost/`, et la documentation de l'API sur `http://localhost/api`. En dev, le serveur écoute sur toutes les interfaces (`SERVER_NAME=:80`) pour qu'un Raspberry Pi du réseau local puisse l'interroger via l'IP de la machine. Pour revenir au HTTPS local auto-signé, définir `SERVER_NAME=localhost` dans `.env.local`.

`make help` liste toutes les commandes disponibles.

Les scripts de `bin/` se lancent depuis le dépôt (ou depuis l'image, comme `bin/deploy`). Ceux de `ops/` ne sont jamais lancés par le dépôt : le mainteneur les installe à la main sur le serveur, comme `ops/ci-deploy` ([exploitation.md](exploitation.md#installer-ou-mettre-à-jour-le-script-de-déploiement)).

## Comptes utilisateurs

L'espace web demande un compte. Deux rôles : `ROLE_USER` gère ses dispositifs, `ROLE_ADMIN` gère en plus les comptes. Un administrateur crée les comptes par invitation (voir plus bas). Pour créer un compte sans passer par l'interface (premier administrateur, accès de secours) :

```bash
make console c="app:user:create lea@example.com --role=ROLE_ADMIN"
```

Le mot de passe (12 caractères minimum) est demandé deux fois, sans s'afficher. L'option `--password` le passe directement, au prix de le laisser dans l'historique du shell. Sans `--role`, le compte est `ROLE_USER`. Le compte est actif tout de suite.

La connexion se fait depuis la vitrine et mène à la liste de ses dispositifs. « Se souvenir de moi » garde la session 30 jours, et les tentatives échouées sont limitées (5 par minute pour un même email et une même IP).

« Mot de passe oublié ? », sous le formulaire de connexion, envoie par email un lien valable 1 h et utilisable une fois. La page de confirmation est la même que l'email corresponde à un compte ou non, et une seule demande par heure est acceptée pour un même compte. Changer son mot de passe ferme les autres sessions et invalide les cookies « Se souvenir de moi ».

Depuis « Mon compte », « Supprimer mon compte » supprime le compte, immédiatement et définitivement, après confirmation par le mot de passe actuel. Ses dispositifs et leurs configurations disparaissent avec lui : leurs jetons cessent de fonctionner. Tout compte peut se supprimer, y compris le dernier administrateur ; l'accès de secours reste `app:user:create`. Un email, sans lien ni détail, prévient l'adresse du compte supprimé, que la suppression vienne de la personne ou d'un administrateur : « Votre compte Stopcast a été supprimé, avec ses écrans d'affichage. Si vous pensez qu'il s'agit d'une erreur, contactez l'administrateur. » Un compte en attente ne reçoit rien : le supprimer annule simplement son invitation.

Depuis « Mon compte », « Télécharger mes données » renvoie un fichier JSON, `stopcast-donnees-AAAA-MM-JJ.json`, pour les droits d'accès et de portabilité : le compte (email, rôle, date de création, date de dernière connexion), ses dispositifs et leurs configurations d'affichage (quai, libellé, position, état, dates en ISO 8601). Il ne contient ni le hash du mot de passe, ni les jetons des dispositifs, ni les identifiants internes ; les logs se demandent par email. La réponse n'est pas mise en cache (`Cache-Control: no-store`).

Un administrateur voit tous les comptes depuis « Utilisateurs » (`/admin/users`), du plus récent au plus ancien : email, rôle, statut (« Actif » ou « Invitation en attente »), nombre de dispositifs et date de création. Ce lien de navigation n'apparaît que pour `ROLE_ADMIN`. « Modifier » change le rôle d'un compte, jamais son email ; ses sessions ouvertes sont alors déconnectées. « Supprimer » supprime un autre compte, avec ses dispositifs et leurs configurations, après une confirmation qui annonce combien de dispositifs partent avec lui ; un administrateur supprime son propre compte depuis « Mon compte », avec son mot de passe.

« Inviter un compte » crée un compte en attente à partir d'un email et d'un rôle, sans mot de passe : il ne peut pas se connecter, et l'échec de connexion ne le révèle pas. L'invité reçoit « Activez votre compte Stopcast », avec un lien valable 24 h et utilisable une fois (en dev, dans Mailpit). Le lien mène à « Choisissez votre mot de passe » (`/invitation`), qui active le compte puis renvoie vers la connexion. Un lien expiré ou invalide renvoie vers « Mot de passe oublié ? », d'où l'invité demande lui-même un nouveau lien : choisir un mot de passe par ce chemin active aussi le compte. Une seule demande par heure étant acceptée pour un même compte, ce chemin ne fonctionne qu'une heure après l'invitation. « Renvoyer l'invitation », dans la ligne d'un compte en attente, envoie un nouveau lien de 24 h, même juste après l'invitation : l'ancien lien cesse aussitôt de fonctionner.

Chaque nuit à 3 h 15 (heure de Paris), le `worker` de prod (transport `scheduler_default`) lance `app:purge` : les comptes en attente dont la dernière invitation a expiré depuis plus de 30 jours sont supprimés, puis les comptes inactifs avertis ou supprimés, et enfin les demandes de réinitialisation expirées des comptes actifs. Les demandes d'un compte en attente restent jusqu'à sa suppression, puisque la plus récente date sa dernière invitation.

Un compte activé est inactif quand personne ne s'y est connecté depuis 2 ans : la date retenue est sa dernière connexion (formulaire ou « se souvenir de moi », jamais l'activité de ses dispositifs), son activation, ou à défaut sa création. À 2 ans moins 30 jours, il reçoit « Votre compte Stopcast va être supprimé », une seule fois ; il est supprimé, avec l'email habituel, quand il a 2 ans d'inactivité et que cet avertissement date de plus de 30 jours. Une connexion entre-temps annule l'avertissement. Le dernier administrateur n'est jamais supprimé : chaque nuit, un `warning` du canal `app` le signale, tant qu'il ne se reconnecte pas. La purge se lance aussi à la main avec `make console c="app:purge"`, qui affiche le nombre d'éléments supprimés ; `make console c="debug:scheduler"` affiche la prochaine exécution.

## Emails

Les emails partent en asynchrone : l'application les met dans la file `async` (table `messenger_messages`), et le service `worker` (`messenger:consume async`) les envoie. En dev, ils arrivent dans Mailpit, sur `http://localhost:8025`, sans jamais quitter la machine.

Le worker garde le code chargé à son démarrage : après une modification de code, le redémarrer.

```bash
make worker-restart
make worker-logs            # suit les envois
```

Après trois échecs, un email passe dans le transport `failed`. Pour le consulter, puis le relancer :

```bash
make console c="messenger:failed:show"
make console c="messenger:failed:retry"
```

Les emails s'appuient sur le layout `templates/email/base.html.twig` (styles en ligne) et sa version texte `base.txt.twig`. En test, la file `async` est en mémoire : les tests lisent les emails en attente avec `assertQueuedEmailCount()` et `getMailerMessage()`.

## Dispositifs

Chaque dispositif appartient à un compte, son propriétaire : lui seul le voit et le gère depuis `/devices`, administrateurs compris. Supprimer un compte supprime ses dispositifs et leurs configurations.

Le jeton d'un dispositif (à recopier dans la configuration de StopcastDisplay) n'est affiché qu'une fois, juste après la création du dispositif ou la régénération de son jeton. Un jeton perdu ne se retrouve pas : en régénérer un, ce qui invalide l'ancien.

Une base qui contient des dispositifs d'avant l'introduction des propriétaires ne peut pas être migrée (la migration `Version20260929175027` échoue). En dev, supprimer les dispositifs de démo, migrer, puis recharger les fixtures :

```bash
make console c="dbal:run-sql 'DELETE FROM display_configuration; DELETE FROM device'"
make migrate
make fixtures
```

Pour la prod locale, repartir d'une base vierge avec `make prod-reset`.

Le formulaire d'un dispositif règle aussi les cadences de sa page d'affichage : la durée d'un écran (5 s par défaut, de 3 à 60 s) et l'intervalle entre deux rafraîchissements des données (60 s par défaut, de 30 à 300 s). Sa page de détail montre l'aperçu de l'écran, qui fait tourner les configurations affichées sur des passages d'exemple, à côté de ses réglages ; un interrupteur y masque ou affiche chaque configuration, et une configuration masquée a son nom grisé (voir [architecture.md](architecture.md#page-daffichage)).

## Configurations d'affichage

Une configuration d'affichage dit quel quai un dispositif affiche. Elles se gèrent depuis `/display-configurations` (toutes celles de ses dispositifs) ou depuis le détail d'un dispositif, avec les mêmes règles d'accès que le dispositif : propriétaire uniquement.

- Le quai se choisit par son nom dans un champ à autocomplétion qui ne propose que les quais actifs, suivis de leurs destinations desservies (« MAIRIE → Centre-ville, Hôtel de ville ») pour distinguer les deux sens de circulation.
- Une nouvelle configuration se place en fin de rotation. Les boutons « Monter » et « Descendre » changent l'ordre dans lequel `/api/next-passages` renvoie les quais. Une configuration masquée garde sa place, mais sort de la réponse de l'API.
- Une configuration dont le quai a disparu du dernier import porte le badge « Quai absent du dernier import ». Elle reste modifiable sans changer de quai.

## Front : Twig et Tailwind

Les pages sont rendues en Twig (voir [ADR 0003](adr/0003-front-twig.md)), stylées avec Tailwind CSS v4 via `symfonycasts/tailwind-bundle`, sans Node : le bundle télécharge le binaire autonome de Tailwind dans `var/tailwind/`.

- `templates/base.html.twig` : squelette commun. `templates/layout/public.html.twig` pour la vitrine, `templates/layout/space.html.twig` pour les pages connectées (navigation de l'espace, messages flash en haut du contenu).
- `templates/components/` : Twig Components anonymes `Button` (`primary`, `secondary`, `danger`, lien si `href`), `Card` (`title` optionnel), `Badge` (`neutral`, `success`, `warning`) et `Flash` (messages flash `success`, `error`, `warning`).
- `templates/form/tailwind_layout.html.twig` : thème de formulaire global, déclaré dans `config/packages/twig.yaml`.
- `templates/bundles/TwigBundle/Exception/` : pages d'erreur de la prod, sur le layout de la vitrine : `error404.html.twig`, `error403.html.twig` et `error.html.twig` pour tout autre code. Elles n'affichent que le statut HTTP, jamais le message de l'exception. Hors debug seulement : en dev, Symfony affiche sa page de debug ; l'aperçu est sur `http://localhost/_error/404` (ou `403`, `500`). Sous `/api`, un client qui demande du JSON garde la réponse d'erreur JSON.
- `templates/display/` : page d'affichage des écrans, autonome, stylée sans Tailwind par `assets/styles/display.css` et chargée par son propre point d'entrée `display` (`assets/display.js`) ; voir [architecture.md](architecture.md#page-daffichage).
- `assets/styles/app.css` : point d'entrée Tailwind. Les dossiers scannés pour trouver les classes y sont listés (`@source`) : un template placé ailleurs doit y être ajouté.
- Feuilles de style liées par un `<link href="{{ asset(…) }}">` dans les templates (`app.css` et le CSS de tom-select dans `base.html.twig`), jamais importées en JavaScript, contrairement à ce que font les recettes Symfony (`import './styles/app.css'`, `autoimport` de `assets/controllers.json`). AssetMapper remplace un import de CSS par un module `data:` que la CSP (`script-src 'self'`) bloque : tout l'import échoue, et le JavaScript de la page avec lui. `NoCssImportedFromJavaScriptTest` échoue si un module JavaScript importe une CSS.
- Scripts en ligne et visites Turbo : un script en ligne apporté par une page que Turbo affiche ne tourne pas. Le morphing (`<meta name="turbo-refresh-method" content="morph">`, sur la page d'un dispositif et la liste des configurations) ne l'exécute pas ; un remplacement de page (lien, retour) l'exécute sous la CSP de la première page chargée, qui refuse le nonce de la nouvelle : la console signale la violation. En dev, la barre de debug, chargée par un tel script, restait donc sur « Loading… » ; `assets/app.js` la charge à l'événement `turbo:render`. Tout script en ligne de l'espace web a le même problème : passer par un contrôleur Stimulus.
- Pages affichées dans une iframe (routes `_frameable`, soit les aperçus de la page d'affichage) : en dev, elles n'ont pas de barre de debug, que `FramedPageToolbarListener` retire. Ses liens ouvriraient le profiler dans l'iframe, et le profiler refuse d'être affiché dans un cadre. La barre de la page principale ne voit pas non plus ces requêtes, l'iframe étant une autre fenêtre : leurs profils se trouvent dans `/_profiler`, en filtrant sur l'URL (`/devices/{id}/preview`).

En dev, le service `tailwind` (même image que `app`) démarre avec `make up` et lance `bin/tailwind-watch` : une compilation complète au démarrage, puis une nouvelle dès que le contenu d'un fichier listé en `@source` dans `app.css` change. Il suffit de recharger la page, une à deux secondes après l'enregistrement. Pour suivre ses recompilations ou une erreur de syntaxe CSS :

```bash
docker compose logs -f tailwind
```

Le script compare chaque seconde une empreinte de ces fichiers : le watcher de Tailwind (`tailwind:build --watch`) manque des modifications faites depuis le Mac sur le bind mount de Docker Desktop (raisons en tête du script). Une compilation en échec est retentée toutes les 5 secondes.

En cas de doute, vérifier ces logs (une ligne `Done in …` par recompilation) ou recompiler à la main : `make console c="tailwind:build"`.

L'image de prod compile la CSS minifiée (`tailwind:build --minify`) puis les assets (`asset-map:compile`) au build : aucun watcher en prod.

Les tests (CI GitHub, `make ci-run`) ne compilent pas la CSS. En environnement `test`, le bundle sert alors `app.css` brut, et AssetMapper passe en `missing_import_mode: warn` pour que l'`@import "tailwindcss"` non résolu ne fasse pas échouer le rendu des pages. Les tests passent donc de la même façon, que la CSS soit compilée ou non.

## Fixtures et écran de développement

`make fixtures` remplace tous les dispositifs et leurs configurations. Le référentiel importé (quais, lignes, trajets) et les comptes créés par `app:user:create` ne sont pas modifiés. Il crée :

- un compte administrateur de développement, recréé à chaque chargement : `admin@stopcast.local`, mot de passe `stopcast-admin`. Il possède tous les dispositifs de démo ;
- 5 dispositifs aléatoires, chacun avec 2 à 4 quais tirés au hasard ;
- si `FIXTURES_DEVICE_TOKEN` est défini, un dispositif « Écran de développement » à jeton fixe, pour le client d'affichage StopcastDisplay.

Les 3 quais de l'écran de développement sont ceux qui ont le plus de passages dans le flux temps réel au moment du chargement, comptés comme les renvoie `/api/next-passages`. Si le flux en donne moins de 3 (la nuit) ou s'il est indisponible, les quais desservis par le plus de lignes complètent la liste, et un avertissement le signale. Les quais ne changent pas ensuite : relancer `make fixtures` pour les recalculer.

Pour brancher StopcastDisplay, sur la machine de développement comme sur le Raspberry Pi :

1. Générer un jeton (64 caractères max) et le définir dans `.env.local` :

   ```bash
   php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'
   # .env.local
   FIXTURES_DEVICE_TOKEN=<jeton>
   ```

2. Lancer `make fixtures`. Le jeton reste le même d'un chargement à l'autre.
3. Dans StopcastDisplay, recopier ce jeton dans `TOKEN`, dans `.env.local` sur la machine et dans `.env.pi.local` sur le Pi. `API_URL` pointe sur l'IP de la machine de développement sur le réseau local (par ex. `http://192.168.1.20`), le Pi ne pouvant pas joindre `localhost`.

Vérifier que l'écran a des passages :

```bash
curl -H 'X-Device-Token: <jeton>' http://localhost/api/next-passages
```

StopcastDisplay appelle cette route toutes les 10 secondes : le profiler ne la profile pas, pour que ces appels ne noient pas ceux du navigateur. Pour en profiler un, ajouter `?_profile=1` (`http://localhost/api/next-passages?_profile=1`) ; le profil apparaît alors dans `/_profiler`.

## Logs

En dev, trois sources, chacune avec sa commande :

| Source | Contenu | Commande |
|---|---|---|
| Fichier de log Symfony | Logs de l'application, du conteneur `app` comme du `worker` | `make dev-log` |
| Sortie des conteneurs | `app` : journal d'accès de Caddy ; `worker` : sortie de `messenger:consume` (envoi des emails) | `make logs`, `make worker-logs` |
| Profiler | Détail d'une requête (logs, requêtes SQL, sécurité…) | `http://localhost/_profiler` |

Le fichier Symfony est dans `var/log/`, qui est le volume Docker `app_var` : il n'est pas visible depuis la machine hôte, seulement dans les conteneurs. Monolog écrit un fichier par jour, `dev-AAAA-MM-JJ.log`, et garde les 7 derniers. Il retient le niveau `info` sur tous les canaux (sauf `event`), et tout le canal `app` dès `debug`. `make dev-log` suit le fichier du jour ; après minuit, relancer la commande pour passer au nouveau fichier. Pour lire un autre jour : `make sh`, puis `ls var/log/`.

Le profiler ne garde pas les appels de StopcastDisplay à `/api/next-passages`, sauf avec `?_profile=1` (voir [Fixtures et écran de développement](#fixtures-et-écran-de-développement)).

## Qualité de code

`make check` lance tous les contrôles de la CI et s'arrête au premier échec. Les contrôles sont dans trois scripts, que la CI GitHub appelle aussi : sur l'hôte (le conteneur `app` n'a pas `docker`), `bin/check-compose` valide les fichiers Compose et `bin/check-scripts` analyse les scripts shell et les workflows ; puis `bin/check` lance les contrôles du code dans le conteneur `app`. Si `make check` passe, la CI passe. C'est le contrôle à faire avant une pull request.

`bin/check-compose` valide `compose.yaml` avec chacune de ses surcharges (`compose.override.yaml`, `compose.ci.yaml`, `compose.prod.yaml`, puis `compose.prod.yaml` avec `compose.prod-local.yaml`) par `docker compose config -q`, sans démarrer de conteneur. La combinaison prod est lue avec `--env-file .env.prod.local.example` : une variable ajoutée à `compose.prod.yaml` sans entrée dans ce modèle fait échouer le contrôle, comme toute erreur ou tout avertissement de Compose. `IMAGE_TAG`, que `bin/deploy` fournit en prod, y reçoit une valeur factice. Le script vérifie aussi qu'en prod aucun service n'a de `build` : le serveur n'a pas les sources. Il valide toutes les combinaisons avant de conclure, pour montrer toutes les erreurs d'un coup.

`bin/check-scripts` passe `shellcheck` sur les scripts de `bin/`, `ops/` et `.githooks/` dont la première ligne lance `sh` ou `bash`, puis `actionlint` sur `.github/workflows/`, chacun par son image Docker à version épinglée (en tête du script) : le premier lancement télécharge les deux images, le hook `pre-push` fonctionne ensuite hors ligne. Une remarque voulue s'ignore sur sa ligne, avec sa raison : `# shellcheck disable=SC2016 # raison`.

Un hook `pre-push` lance `make check` avant chaque push et bloque le push en cas d'échec. `make up` l'active (`make hooks` le fait seul, sans démarrer les conteneurs). Si les conteneurs sont arrêtés, le hook bloque aussi le push et demande un `make up`. Un push qui ne fait que supprimer des branches ou des tags distants ne lance pas les contrôles. Pour pousser sans contrôle, par exemple une branche de travail : `git push --no-verify`.

Un hook `pre-commit`, activé de la même façon, applique Rector puis PHP CS Fixer aux fichiers PHP du commit et les ré-indexe : le style est corrigé avant le commit plutôt que refusé au push. Il ne fait rien si les conteneurs sont arrêtés ou si un fichier n'est indexé qu'en partie (le ré-indexer embarquerait le reste) : `make check` le contrôlera au push. `make fix` applique les mêmes corrections à tout le projet.

PHPStan refuse les appels de débogage (`dump()`, `dd()`, `var_dump()`… : liste dans `phpstan.dist.neon`). Pour un usage légitime, l'ignorer ponctuellement avec `// @phpstan-ignore disallowed.function (raison)`.

| Commande | Outil |
|---|---|
| `make check` | Tous les contrôles de la CI |
| `make fix` | Rector puis PHP CS Fixer, en correction |
| `make test` | PHPUnit |
| `make phpstan` | PHPStan (niveau 10) |
| `make cs-fixer` / `make cs-fixer-fix` | PHP CS Fixer (règles `@Symfony`) |
| `make rector` / `make rector-fix` | Rector |
| `make audit` | Failles connues des dépendances (`composer audit`, `importmap:audit`) |

La CI (GitHub Actions) lance ces contrôles à chaque push sur `main` et `develop` et sur chaque pull request. Sur `main`, s'ils passent, elle construit l'image de prod et la déploie : voir [exploitation.md](exploitation.md#déploiement).

`make audit` lance `bin/audit`, qui cherche les failles connues dans les dépendances PHP et JS. Il interroge les bases de failles en ligne : il n'est donc pas dans `make check`, pour que le hook `pre-push` fonctionne hors ligne et qu'une faille publiée du jour au lendemain ne bloque pas une pull request sans rapport. Le workflow GitHub `audit.yml` lance le même script à chaque push sur `main` et `develop`, sur chaque pull request, tous les jours à 6 h UTC et à la demande. Un échec du passage quotidien arrive par email : c'est le signal pour mettre à jour la dépendance concernée. Le passage quotidien scanne aussi la dernière image de prod publiée avec Trivy, qui vérifie déjà chaque image avant sa publication : voir [exploitation.md](exploitation.md#scan-des-failles-trivy).

## Pièges en test

Comportements qu'on ne découvre qu'en lançant les tests. Les gestes communs aux tests de l'espace web (connexion, déconnexion, ligne de la liste des comptes, jeton d'un lien reçu par email, jeton CSRF qu'aucune page n'affiche) sont dans `tests/Support/WebTestTrait.php`.

- La navigation de l'espace contient un formulaire (« Se déconnecter », en POST) : viser le formulaire de la page avec `main form`, pas `form`, qui attrape d'abord celui du header.
- Un jeton CSRF invalide sur une action `#[IsCsrfTokenValid]` lève `InvalidCsrfTokenException`, une exception d'authentification : la réponse est une redirection 302 vers la connexion (`http://localhost/`), pas un 403.
- `loginUser()` réutilise la session du cookie en cours. Pour deux sessions distinctes (un compte et un administrateur, par exemple), vider le cookie jar (`getCookieJar()->clear()`) entre les deux connexions.
- Les rôles en session sont comparés à ceux relus en base : après avoir changé le rôle d'un compte, le relire (`refresh()`) avant `loginUser()`, sinon sa session est déconnectée à la requête suivante.
- La durée d'un lien du reset-password-bundle (`expirationMessageKey|trans`) s'affiche dans la plus grande unité entière : 86 400 s donne « 1 jour », pas « 24 heures ».
- Les limites d'essais (connexion, mot de passe actuel et demandes de réinitialisation) partagent en test un stockage en mémoire, `app.rate_limiter.test_storage`, qui ne survit d'une requête à l'autre qu'avec `disableReboot()` (voir `CurrentPasswordThrottlingTest`, `ResetPasswordThrottlingTest` et `TrustedProxiesTest`) ; sans lui, chaque requête repart d'un compteur vide.
- `TRUSTED_PROXIES` est lu au démarrage du noyau et appliqué en statique sur `Request` : pour le fixer dans un test, le définir dans `$_ENV` et `$_SERVER` avant `createClient()`, puis remettre la valeur d'origine et `Request::setTrustedProxies([], 0)` dans `tearDown()` (voir `TrustedProxiesTest`). Le client de test envoie ses requêtes depuis `127.0.0.1`, avec l'hôte `localhost`.
- Les tests des pages d'erreur (`ErrorPagesTest`) tournent avec un noyau sans debug, dont le conteneur (`var/cache/test/App_KernelTestContainer.php`) n'est jamais reconstruit : après un changement de configuration ou de service, vider le cache de test (`make console c="cache:clear --env=test"`). Les templates, eux, sont relus (`twig.auto_reload` en test).
- `UserFactory::new()->pending()` crée un compte en attente : la factory écrit le mot de passe directement, `setPassword()` refusant `null`.
- `MockClock` lit une date donnée en texte en UTC, et `mockTime()` sans argument fige l'heure en UTC, alors que PHP tourne en `Europe/Paris` (voir [Fuseau horaire](architecture.md#fuseau-horaire)) : passer le fuseau de PHP (`new MockClock(self::NOW, date_default_timezone_get())`, `mockTime(new \DateTimeImmutable())`), sinon l'horloge simulée est décalée d'une ou deux heures par rapport aux dates des tests et des entités. Les tests du fuseau (`TimezoneTest`, `NextPassagesTimezoneTest`) échouent hors de l'image si `date.timezone` n'est pas réglé.

## Mettre à jour les dépendances

1. Regarder d'abord ce qui bouge : `make composer c='update "symfony/*" --dry-run'` (ou les paquets visés). Un motif comme `symfony/*` attrape aussi les paquets en contrainte `^` (UX, maker-bundle), qui peuvent changer de version mineure.
2. Lancer la mise à jour sans `--dry-run`. `bump-after-update` est activé : Composer remonte les contraintes de `composer.json` aux versions installées.
3. `make composer c="recipes"` liste les recipes à mettre à jour. Chacune se met à jour avec `make composer c="recipes:update <paquet>"`. Flex refuse de tourner tant que l'index git contient des changements : committer entre deux.
4. Finir par `make audit` puis `make check`.

## Migrations

`make migration` génère une migration à partir des entités, `make migrate` l'applique (voir aussi `CODING_STANDARDS.md`).

En prod, `bin/deploy` passe les migrations avec la nouvelle image, pendant que l'ancien code sert encore, puis remplace les conteneurs ([exploitation.md](exploitation.md#bindeploy)). L'ancien code tourne donc quelques secondes sur le nouveau schéma. Ce n'est pas une obligation, mais préférer les migrations additives, que l'ancien code supporte :

- ajouter une colonne nullable (ou avec une valeur par défaut), puis la rendre obligatoire dans une release suivante ;
- renommer en deux temps : ajouter la nouvelle colonne et y recopier les données, puis supprimer l'ancienne dans une release suivante, une fois le code passé à la nouvelle ;
- supprimer une colonne ou une table seulement quand le code en place ne s'en sert plus.

Toutes les migrations en attente passent dans une seule transaction (`all_or_nothing`) : si l'une échoue, le lot entier est annulé.

Pas de méthode `down()` à maintenir : le schéma ne fait qu'avancer. Un retour arrière qui ne supporte pas le nouveau schéma passe par la restauration du dump pris avant migration ([exploitation.md](exploitation.md#retour-arrière)).

## Environnement de prod (local)

```bash
cp .env.prod.local.example .env.prod.local   # puis renseigner les valeurs
make down                                    # libère le port 80 du dev
make prod-up
```

Les secrets de la prod réelle (serveur) ne suivent pas ce modèle : voir [exploitation.md](exploitation.md#secrets).

La prod locale construit l'image depuis les sources (`compose.prod-local.yaml` remet le `build`), sous le nom `ghcr.io/v-gael/stopcast:local` (`IMAGE_TAG=local`, fixé par le `Makefile`). La prod réelle ne construit rien : elle tire de GHCR l'image construite par la CI, étiquetée par le SHA du commit ([exploitation.md](exploitation.md#déploiement)).

La prod locale tourne sur `http://localhost/`, en HTTP seul, sans TLS ni redirection : sur le serveur, le TLS est terminé par Cloudflare et le trafic arrive par Cloudflare Tunnel (`cloudflared`) sur le réseau Docker externe `edge`, où `app` répond sous l'alias `stopcast-app` sans publier de port (`compose.prod.yaml`, [ADR 0006](adr/0006-hebergement.md)). En local, `compose.prod-local.yaml` publie le port 80 et fait de `edge` un réseau local créé par Compose. La prod locale ne teste donc ni le HTTPS ni le tunnel.

Comme en prod, `app` et `worker` tournent sous `www-data`, sans capacités Linux superflues ([exploitation.md](exploitation.md#utilisateur-et-capacités)) : `docker compose exec` y ouvre une session `www-data`, qui n'écrit que dans `var/` et `/tmp`.

La prod locale sert l'appli en mode worker de FrankenPHP, comme la prod, alors que le dev reste en mode classique : tester en prod locale tout changement qui garde un état dans un service ([architecture.md](architecture.md#mode-worker-en-prod)). Les logs de `app` (`make prod-logs`) montrent au démarrage les workers lancés sur `public/index.php`.

La prod locale lance aussi un `worker`. Sans `MAILER_DSN` dans `.env.prod.local`, aucun email ne part (`null://null`) ; `make prod-logs` suit `app` et `worker`. La CI ne lance pas de worker.

`make prod-verify` (`bin/prod-verify`) contrôle l'image de prod, ce que ni `make check` ni la CI ne font avant `main` : à lancer quand une modification touche le `Dockerfile`, un fichier Compose, `frankenphp/`, `bin/deploy` ou `ops/`. Il construit l'image `local`, vérifie sa taille (somme des couches, plafond `MAX_IMAGE_MB` en tête du script), la déploie par `bin/deploy` (migrations comprises), contrôle `app` et `worker` (`www-data`, capacités, `no-new-privileges`, fuseau, mode worker, écriture dans `var/`, aucun redémarrage du `worker`), puis lance `bin/smoke-test http://localhost`. Il passe tous les contrôles avant de conclure et arrête la prod locale à la fin, volumes gardés ; `bin/prod-verify --keep` la laisse tourner pour inspecter. Comme `make prod-up`, il demande le dev arrêté.

Dev, prod locale et CI locale sont trois projets Docker Compose distincts (`stopcast`, le nom du dossier, puis `stopcast-prod`, `stopcast-ci`), avec chacun leurs conteneurs et leur base : `make prod-reset` ou `make ci-down` ne touchent jamais aux données de dev. Le dev et la prod locale publient tous deux le port 80 : arrêter l'un (`make down` ou `make prod-down`) avant de lancer l'autre. La CI locale (`make ci-run`) ne publie aucun port et peut tourner pendant le dev ; elle repart à chaque fois d'une base vierge.

Depuis le renommage du projet en Stopcast, les projets Compose et l'image de dev portent le nouveau nom (`stopcast-prod`, `stopcast-ci`, image `stopcast-dev`, et `stopcast` pour le dev une fois le dossier renommé) : Compose crée de nouveaux conteneurs et de nouveaux volumes, et la base de dev repart vide. `make up`, `make migrate` et `make import` la recréent, puis `make fixtures` la remplit (le compte de dev devient `admin@stopcast.local`). Les conteneurs, volumes et l'image de l'ancien nom restent sur la machine : `docker compose ls -a` et `docker image ls` les montrent, `docker compose -p <ancien projet> down -v` et `docker image rm <ancienne image>` les suppriment.

## Proxy et hôtes de confiance

Deux variables d'environnement disent à Symfony ce qu'il peut croire des en-têtes de la requête (`config/packages/framework.yaml`) :

- `TRUSTED_PROXIES` : adresses IP ou plages CIDR des proxys placés devant l'application, séparées par des virgules. Quand la requête vient de l'un d'eux, Symfony lit l'IP du client, le protocole (HTTPS), l'hôte et le port dans `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host` et `X-Forwarded-Port`. Sans cela, il voit l'IP du proxy : la limite d'essais à la connexion (par identifiant + IP) mettrait tous les utilisateurs dans le même compteur, les logs perdraient l'IP réelle et l'application, servie en HTTP derrière le proxy, générerait des liens en `http://`. Vide par défaut : aucun proxy, ces en-têtes sont ignorés. `CF-Connecting-IP` n'est jamais lu.
- `TRUSTED_HOSTS` : hôtes acceptés dans l'en-tête `Host` (ou `X-Forwarded-Host` venant d'un proxy de confiance), en expressions régulières séparées par des virgules ; tout autre hôte reçoit une 400. Les liens des emails (réinitialisation du mot de passe, invitation) sont construits pendant la requête à partir de cet hôte : sans liste, un `Host` forgé produirait un lien vers un domaine piégé. Ancrer chaque motif (`^…$`) : `localhost` seul accepterait aussi `localhost.piege.example`. Vide, tout hôte est accepté.

Valeurs par environnement :

| Environnement | `TRUSTED_PROXIES` | `TRUSTED_HOSTS` |
|---|---|---|
| `.env` (tests compris) | vide | `localhost` et `127.0.0.1` |
| `.env.dev` | vide | `localhost` et toute adresse IPv4, pour le Raspberry Pi du réseau local |
| Prod (`compose.prod.yaml`, depuis `.env.prod.local`) | plage du réseau `edge` (`172.30.0.0/24` sur le serveur) | domaine servi (`stopcast.gaelv.com`) et `localhost`, que le healthcheck interroge (`curl http://localhost/`) |

`compose.prod.yaml` passe les deux variables aux services `app` et `worker` : une valeur vide dans `.env.prod.local` l'emporte sur celle de `.env`, et désactive donc la vérification de l'hôte.

## Conventions

- Règles de code vérifiées en relecture, en plus de `make check` : [CODING_STANDARDS.md](../CODING_STANDARDS.md).
- Commits au format [Conventional Commits](https://www.conventionalcommits.org/), en anglais.
- Gitflow : branches `feature/<nom>` créées depuis `develop`, mergées en `--no-ff` ; `main` ne reçoit que les releases.
- Quand `develop` avance pendant qu'une PR est ouverte, fusionner `develop` dans la branche en local, puis pousser : relancer un check de la PR ne suffit pas, il réutilise l'ancien commit de fusion avec `develop`.
