.DEFAULT_GOAL := help
.PHONY: help up down build sh console cc composer test test-verbose check hooks rector rector-fix cs-fixer cs-fixer-fix phpstan import fixtures migrate migration db-reset logs worker-restart worker-logs prod-up prod-down prod-logs prod-ps prod-reset ci-run ci-down

# Commandes de base des environnements prod et CI (locaux, pour tester). Chacun
# a son propre projet compose : sans -p, ils partageraient le projet du dev
# (nom du dossier), donc ses conteneurs et sa base (un `down -v` la supprimerait).
PROD := docker compose -p abribus-prod --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml
CI := docker compose -p abribus-ci -f compose.yaml -f compose.ci.yaml

## —— Docker ———————————————————————————————————————————————————————————————
up: hooks ## Démarre les conteneurs (dev) et active le hook pre-push
	docker compose up -d

down: ## Arrête les conteneurs
	docker compose down

build: ## Rebuild les images
	docker compose up -d --build

sh: ## Ouvre un shell dans le conteneur app
	docker compose exec app sh

logs: ## Suit les logs du conteneur app
	docker compose logs -f app

worker-restart: ## Redémarre le worker (à faire après un changement de code)
	docker compose restart worker

worker-logs: ## Suit les logs du worker (envoi des emails)
	docker compose logs -f worker

## —— Prod (local, pour tester) ———————————————————————————————————————————
prod-up: ## Build + démarre avec la stage prod et .env.prod.local (arrêter le dev avant : mêmes ports)
	$(PROD) up -d --build

prod-down: ## Arrête l'environnement prod
	$(PROD) down

prod-logs: ## Suit les logs de l'environnement prod (app et worker)
	$(PROD) logs -f app worker

prod-ps: ## État des conteneurs prod (vérifier healthy)
	$(PROD) ps

prod-reset: ## ⚠️ Supprime volumes + rebuild + remigre à zéro (reset complet du test prod)
	$(PROD) down -v
	$(PROD) up -d --build
	$(PROD) exec app php bin/console doctrine:migrations:migrate --no-interaction

## —— CI (local, pour tester) —————————————————————————————————————————————
ci-run: ## Build + lance la suite de tests façon CI sur une base vierge, retourne le code de sortie
	$(CI) down -v
	$(CI) up --build --abort-on-container-exit --exit-code-from app

ci-down: ## Nettoie les conteneurs/volumes de l'environnement CI
	$(CI) down -v

## —— Symfony ——————————————————————————————————————————————————————————————
console: ## Lance bin/console avec les arguments passés (ex: make console c="make:entity")
	docker compose exec app php bin/console $(c)

cc: ## Vide le cache Symfony (cache:clear)
	docker compose exec app php bin/console cache:clear

composer: ## Lance composer avec les arguments passés (ex: make composer c="require --dev foo/bar")
	docker compose exec app composer $(c)

## —— Qualité de code ——————————————————————————————————————————————————————
check: ## Lance tous les contrôles de la CI (à faire avant une PR)
	docker compose exec app bin/check

hooks: ## Active le hook pre-push du dépôt (lance make check avant chaque push)
	@git config core.hooksPath .githooks

test: ## Lance PHPUnit
	docker compose exec -e APP_ENV=test app php vendor/bin/phpunit --display-notices --display-phpunit-notices --testdox

test-verbose: ## Lance PHPUnit avec plus de détails
	docker compose exec -e APP_ENV=test app php vendor/bin/phpunit --display-notices --display-phpunit-notices --testdox --verbose

rector: ## Lance Rector en dry-run
	docker compose exec app php vendor/bin/rector process --dry-run --debug

rector-fix: ## Applique les corrections Rector
	docker compose exec app php vendor/bin/rector process

cs-fixer: ## Vérifie le style de code (dry-run)
	docker compose exec app php vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fixer-fix: ## Corrige le style de code
	docker compose exec app php vendor/bin/php-cs-fixer fix

phpstan: ## Lance l'analyse statique PHPStan
	docker compose exec app php vendor/bin/phpstan analyse

## —— Métier ———————————————————————————————————————————————————————————————
import: ## Lance l'import GTFS
	docker compose exec app php bin/console app:gtfs:import

fixtures: ## Remplace les dispositifs de démo (--append : conserve le référentiel importé via make import)
	docker compose exec app php bin/console doctrine:fixtures:load --append --no-interaction

## —— Base de données ——————————————————————————————————————————————————————
migrate: ## Applique les migrations en attente
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

migration: ## Génère une migration à partir des entités
	docker compose exec app php bin/console make:migration

db-reset: ## Supprime et recrée la base (⚠️ perte de données)
	docker compose exec app php bin/console doctrine:database:drop --force --if-exists
	docker compose exec app php bin/console doctrine:database:create
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

## —— Aide —————————————————————————————————————————————————————————————————
help: ## Affiche cette aide
	@grep -E '(^[a-zA-Z0-9_-]+:.*?## .*$$)|(^## )' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; /^## / {printf "\n\033[1m%s\033[0m\n", substr($$0, 4)} /^[a-zA-Z0-9_-]+:/ {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'
