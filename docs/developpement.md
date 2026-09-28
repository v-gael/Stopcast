# Développement

## Prérequis

- Docker et Docker Compose
- `make`

L'application tourne dans un conteneur FrankenPHP (PHP 8.4), avec une base PostgreSQL 16.

## Démarrage

```bash
cp .env .env.local           # puis renseigner les URLs GTFS et ADMIN_API_KEY (voir docs/api.md)
make up                      # démarre les conteneurs de dev
make migrate                 # applique les migrations
make import                  # importe le référentiel GTFS statique
make fixtures                # (optionnel) crée des dispositifs de démo sur les quais importés
```

L'API est ensuite disponible sur `http://localhost/api`. En dev, le serveur écoute sur toutes les interfaces (`SERVER_NAME=:80`) pour qu'un Raspberry Pi du réseau local puisse l'interroger via l'IP de la machine. Pour revenir au HTTPS local auto-signé, définir `SERVER_NAME=localhost` dans `.env.local`.

`make help` liste toutes les commandes disponibles.

## Déboguer le flux temps réel

```bash
make console c="app:gtfs:rt-debug <gtfsStopId>"
```

Affiche ce que le flux GTFS-RT contient pour un quai donné, utile pour vérifier la correspondance des identifiants entre flux statique et temps réel.

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

Dev, prod locale et CI locale sont trois projets Docker Compose distincts (`abribus`, `abribus-prod`, `abribus-ci`), avec chacun leurs conteneurs et leur base : `make prod-reset` ou `make ci-down` ne touchent jamais aux données de dev. Le dev et la prod locale publient les mêmes ports (80/443) : arrêter l'un (`make down` ou `make prod-down`) avant de lancer l'autre. La CI locale (`make ci-run`) ne publie aucun port et peut tourner pendant le dev ; elle repart à chaque fois d'une base vierge.

## Conventions

- Commits au format [Conventional Commits](https://www.conventionalcommits.org/), en anglais.
- Gitflow : branches `feature/<nom>` créées depuis `develop`, mergées en `--no-ff` ; `main` ne reçoit que les releases.
