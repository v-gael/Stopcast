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

## Comptes utilisateurs

L'espace web demande un compte. Deux rôles : `ROLE_USER` gère ses dispositifs, `ROLE_ADMIN` gère en plus les comptes. Un administrateur crée les comptes par invitation (voir plus bas). Pour créer un compte sans passer par l'interface (premier administrateur, accès de secours) :

```bash
make console c="app:user:create lea@example.com --role=ROLE_ADMIN"
```

Le mot de passe (12 caractères minimum) est demandé deux fois, sans s'afficher. L'option `--password` le passe directement, au prix de le laisser dans l'historique du shell. Sans `--role`, le compte est `ROLE_USER`. Le compte est actif tout de suite.

La connexion se fait depuis la vitrine et mène à la liste de ses dispositifs. « Se souvenir de moi » garde la session 30 jours, et les tentatives échouées sont limitées (5 par minute pour un même email et une même IP).

« Mot de passe oublié ? », sous le formulaire de connexion, envoie par email un lien valable 1 h et utilisable une fois. La page de confirmation est la même que l'email corresponde à un compte ou non, et une seule demande par heure est acceptée pour un même compte. Changer son mot de passe ferme les autres sessions et invalide les cookies « Se souvenir de moi ».

Depuis « Mon compte », « Supprimer mon compte » supprime le compte, immédiatement et définitivement, après confirmation par le mot de passe actuel. Ses dispositifs et leurs configurations disparaissent avec lui : leurs jetons cessent de fonctionner. Tout compte peut se supprimer, y compris le dernier administrateur ; l'accès de secours reste `app:user:create`. Un email, sans lien ni détail, prévient l'adresse du compte supprimé, que la suppression vienne de la personne ou d'un administrateur : « Votre compte Stopcast a été supprimé, avec ses écrans d'affichage. Si vous pensez qu'il s'agit d'une erreur, contactez l'administrateur. » Un compte en attente ne reçoit rien : le supprimer annule simplement son invitation.

Un administrateur voit tous les comptes depuis « Utilisateurs » (`/admin/users`), du plus récent au plus ancien : email, rôle, statut (« Actif » ou « Invitation en attente »), nombre de dispositifs et date de création. Ce lien de navigation n'apparaît que pour `ROLE_ADMIN`. « Modifier » change le rôle d'un compte, jamais son email ; ses sessions ouvertes sont alors déconnectées. « Supprimer » supprime un autre compte, avec ses dispositifs et leurs configurations, après une confirmation qui annonce combien de dispositifs partent avec lui ; un administrateur supprime son propre compte depuis « Mon compte », avec son mot de passe.

« Inviter un compte » crée un compte en attente à partir d'un email et d'un rôle, sans mot de passe : il ne peut pas se connecter, et l'échec de connexion ne le révèle pas. L'invité reçoit « Activez votre compte Stopcast », avec un lien valable 24 h et utilisable une fois (en dev, dans Mailpit). Le lien mène à « Choisissez votre mot de passe » (`/invitation`), qui active le compte puis renvoie vers la connexion. Un lien expiré ou invalide renvoie vers « Mot de passe oublié ? », d'où l'invité demande lui-même un nouveau lien : choisir un mot de passe par ce chemin active aussi le compte. Une seule demande par heure étant acceptée pour un même compte, ce chemin ne fonctionne qu'une heure après l'invitation. « Renvoyer l'invitation », dans la ligne d'un compte en attente, envoie un nouveau lien de 24 h, même juste après l'invitation : l'ancien lien cesse aussitôt de fonctionner.

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
- `assets/styles/app.css` : point d'entrée Tailwind. Les dossiers scannés pour trouver les classes y sont listés (`@source`) : un template placé ailleurs doit y être ajouté.

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

`make check` lance tous les contrôles de la CI et s'arrête au premier échec. Les contrôles sont dans deux scripts, que la CI GitHub appelle aussi : `bin/check-compose` valide les fichiers Compose, sur l'hôte (le conteneur `app` n'a pas `docker`), puis `bin/check` lance les contrôles du code dans le conteneur `app`. Si `make check` passe, la CI passe. C'est le contrôle à faire avant une pull request.

`bin/check-compose` valide `compose.yaml` avec chacune de ses surcharges (`compose.override.yaml`, `compose.ci.yaml`, `compose.prod.yaml`) par `docker compose config -q`, sans démarrer de conteneur. La combinaison prod est lue avec `--env-file .env.prod.local.example` : une variable ajoutée à `compose.prod.yaml` sans entrée dans ce modèle fait échouer le contrôle, comme toute erreur ou tout avertissement de Compose. Le script valide les trois combinaisons avant de conclure, pour montrer toutes les erreurs d'un coup.

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

La CI (GitHub Actions) lance ces contrôles à chaque push sur `main` et `develop` et sur chaque pull request.

`make audit` lance `bin/audit`, qui cherche les failles connues dans les dépendances PHP et JS. Il interroge les bases de failles en ligne : il n'est donc pas dans `make check`, pour que le hook `pre-push` fonctionne hors ligne et qu'une faille publiée du jour au lendemain ne bloque pas une pull request sans rapport. Le workflow GitHub `audit.yml` lance le même script à chaque push sur `main` et `develop`, sur chaque pull request, tous les jours à 6 h UTC et à la demande. Un échec du passage quotidien arrive par email : c'est le signal pour mettre à jour la dépendance concernée.

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

## Mettre à jour les dépendances

1. Regarder d'abord ce qui bouge : `make composer c='update "symfony/*" --dry-run'` (ou les paquets visés). Un motif comme `symfony/*` attrape aussi les paquets en contrainte `^` (UX, maker-bundle), qui peuvent changer de version mineure.
2. Lancer la mise à jour sans `--dry-run`. `bump-after-update` est activé : Composer remonte les contraintes de `composer.json` aux versions installées.
3. `make composer c="recipes"` liste les recipes à mettre à jour. Chacune se met à jour avec `make composer c="recipes:update <paquet>"`. Flex refuse de tourner tant que l'index git contient des changements : committer entre deux.
4. Finir par `make audit` puis `make check`.

## Environnement de prod (local)

```bash
cp .env.prod.local.example .env.prod.local   # puis renseigner les valeurs
make down                                    # libère les ports 80/443 du dev
make prod-up
```

La prod locale redirige `http://localhost/` vers HTTPS par une redirection permanente (308), que le navigateur garde en cache. De retour en dev (HTTP seul), vider le cache du navigateur pour `localhost`, ou passer par `http://127.0.0.1/`.

La prod locale lance aussi un `worker`. Sans `MAILER_DSN` dans `.env.prod.local`, aucun email ne part (`null://null`) ; `make prod-logs` suit `app` et `worker`. La CI ne lance pas de worker.

Dev, prod locale et CI locale sont trois projets Docker Compose distincts (`stopcast`, le nom du dossier, puis `stopcast-prod`, `stopcast-ci`), avec chacun leurs conteneurs et leur base : `make prod-reset` ou `make ci-down` ne touchent jamais aux données de dev. Le dev et la prod locale publient les mêmes ports (80/443) : arrêter l'un (`make down` ou `make prod-down`) avant de lancer l'autre. La CI locale (`make ci-run`) ne publie aucun port et peut tourner pendant le dev ; elle repart à chaque fois d'une base vierge.

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
| Prod (`compose.prod.yaml`, depuis `.env.prod.local`) | plage du réseau du proxy | domaine servi et `localhost`, que le healthcheck interroge (`curl http://localhost/`) |

`compose.prod.yaml` passe les deux variables aux services `app` et `worker` : une valeur vide dans `.env.prod.local` l'emporte sur celle de `.env`, et désactive donc la vérification de l'hôte.

## Conventions

- Règles de code vérifiées en relecture, en plus de `make check` : [CODING_STANDARDS.md](../CODING_STANDARDS.md).
- Commits au format [Conventional Commits](https://www.conventionalcommits.org/), en anglais.
- Gitflow : branches `feature/<nom>` créées depuis `develop`, mergées en `--no-ff` ; `main` ne reçoit que les releases.
