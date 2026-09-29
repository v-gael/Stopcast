# Développement

## Prérequis

- Docker et Docker Compose
- `make`

L'application tourne dans un conteneur FrankenPHP (PHP 8.5), avec une base PostgreSQL 16.

## Démarrage

```bash
cp .env .env.local           # puis renseigner les URLs GTFS et ADMIN_API_KEY (voir docs/api.md)
make up                      # démarre les conteneurs de dev
make migrate                 # applique les migrations
make import                  # importe le référentiel GTFS statique
make fixtures                # (optionnel) crée des dispositifs de démo sur les quais importés (voir plus bas)
```

La vitrine est ensuite disponible sur `http://localhost/`, et l'API sur `http://localhost/api`. En dev, le serveur écoute sur toutes les interfaces (`SERVER_NAME=:80`) pour qu'un Raspberry Pi du réseau local puisse l'interroger via l'IP de la machine. Pour revenir au HTTPS local auto-signé, définir `SERVER_NAME=localhost` dans `.env.local`.

`make help` liste toutes les commandes disponibles.

## Front : Twig et Tailwind

Les pages sont rendues en Twig (voir [ADR 0003](adr/0003-front-twig.md)), stylées avec Tailwind CSS v4 via `symfonycasts/tailwind-bundle`, sans Node : le bundle télécharge le binaire autonome de Tailwind dans `var/tailwind/`.

- `templates/base.html.twig` : squelette commun. `templates/layout/public.html.twig` pour la vitrine, `templates/layout/space.html.twig` pour les pages connectées (bloc `navigation`, messages flash en haut du contenu).
- `templates/components/` : Twig Components anonymes `Button` (`primary`, `secondary`, `danger`, lien si `href`), `Card` (`title` optionnel), `Badge` (`neutral`, `success`, `warning`) et `Flash` (messages flash `success`, `error`, `warning`).
- `templates/form/tailwind_layout.html.twig` : thème de formulaire global, déclaré dans `config/packages/twig.yaml`.
- `assets/styles/app.css` : point d'entrée Tailwind. Les dossiers scannés pour trouver les classes y sont listés (`@source`) : un template placé ailleurs doit y être ajouté.

En dev, le service `tailwind` (même image que `app`) démarre avec `make up` et lance `tailwind:build --watch` : modifier une classe dans un template recompile la CSS, il suffit de recharger la page. Pour suivre ses recompilations ou une erreur de syntaxe CSS :

```bash
docker compose logs -f tailwind
```

Si une modification n'est pas prise en compte, vérifier d'abord ces logs (une ligne `Done in …` par recompilation), puis relancer le service avec `docker compose restart tailwind`. L'option `--poll` du bundle n'existe plus avec Tailwind v4 : les événements de fichiers du bind mount de Docker Desktop suffisent sur macOS.

L'image de prod compile la CSS minifiée (`tailwind:build --minify`) puis les assets (`asset-map:compile`) au build : aucun watcher en prod.

## Fixtures et écran de développement

`make fixtures` remplace tous les dispositifs et leurs configurations. Le référentiel importé (quais, lignes, trajets) n'est pas modifié. Il crée :

- 5 dispositifs aléatoires, chacun avec 2 à 4 quais tirés au hasard ;
- si `FIXTURES_DEVICE_TOKEN` est défini, un dispositif « Écran de développement » à jeton fixe, pour le client d'affichage AbribusDisplay.

Les 3 quais de l'écran de développement sont ceux qui ont le plus de passages dans le flux temps réel au moment du chargement, comptés comme les renvoie `/api/next-passages`. Si le flux en donne moins de 3 (la nuit) ou s'il est indisponible, les quais desservis par le plus de lignes complètent la liste, et un avertissement le signale. Les quais ne changent pas ensuite : relancer `make fixtures` pour les recalculer.

Pour brancher AbribusDisplay, sur la machine de développement comme sur le Raspberry Pi :

1. Générer un jeton (64 caractères max) et le définir dans `.env.local` :

   ```bash
   php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'
   # .env.local
   FIXTURES_DEVICE_TOKEN=<jeton>
   ```

2. Lancer `make fixtures`. Le jeton reste le même d'un chargement à l'autre.
3. Dans AbribusDisplay, recopier ce jeton dans `TOKEN`, dans `.env.local` sur la machine et dans `.env.pi.local` sur le Pi. `API_URL` pointe sur l'IP de la machine de développement sur le réseau local (par ex. `http://192.168.1.20`), le Pi ne pouvant pas joindre `localhost`.

Vérifier que l'écran a des passages :

```bash
curl -H 'X-Device-Token: <jeton>' http://localhost/api/next-passages
```

## Qualité de code

| Commande | Outil |
|---|---|
| `make test` | PHPUnit |
| `make phpstan` | PHPStan (niveau 10) |
| `make cs-fixer` / `make cs-fixer-fix` | PHP CS Fixer (règles `@Symfony`) |
| `make rector` / `make rector-fix` | Rector |

Les mêmes vérifications tournent en CI (GitHub Actions) à chaque push et pull request.

## Environnement de prod (local)

```bash
cp .env.prod.local.example .env.prod.local   # puis renseigner les valeurs
make down                                    # libère les ports 80/443 du dev
make prod-up
```

La prod locale redirige `http://localhost/` vers HTTPS par une redirection permanente (308), que le navigateur garde en cache. De retour en dev (HTTP seul), vider le cache du navigateur pour `localhost`, ou passer par `http://127.0.0.1/`.

Dev, prod locale et CI locale sont trois projets Docker Compose distincts (`abribus`, `abribus-prod`, `abribus-ci`), avec chacun leurs conteneurs et leur base : `make prod-reset` ou `make ci-down` ne touchent jamais aux données de dev. Le dev et la prod locale publient les mêmes ports (80/443) : arrêter l'un (`make down` ou `make prod-down`) avant de lancer l'autre. La CI locale (`make ci-run`) ne publie aucun port et peut tourner pendant le dev ; elle repart à chaque fois d'une base vierge.

## Conventions

- Commits au format [Conventional Commits](https://www.conventionalcommits.org/), en anglais.
- Gitflow : branches `feature/<nom>` créées depuis `develop`, mergées en `--no-ff` ; `main` ne reçoit que les releases.
