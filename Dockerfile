# syntax=docker/dockerfile:1

# =============================================================================
# base : dépendances système communes à toutes les stages
# =============================================================================
FROM dunglas/frankenphp:1-php8.5-alpine AS base

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apk add --no-cache \
        file \
        gettext \
        git \
        icu-data-full \
    && install-php-extensions \
        @composer \
        apcu \
        ctype \
        iconv \
        intl \
        pdo_pgsql \
        zip

# Fuseau horaire commun à toutes les stages.
COPY frankenphp/conf.d/10-timezone.ini $PHP_INI_DIR/conf.d/
COPY frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]


# =============================================================================
# dev : environnement de développement local
#   -> le code applicatif est monté en bind mount via compose.override.yaml,
#      il n'y a donc pas de COPY . . ici.
# =============================================================================
FROM base AS dev

ENV APP_ENV=dev \
    XDEBUG_MODE=off

RUN install-php-extensions xdebug

COPY frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/conf.d/

# /app est un bind mount qui n'appartient pas à root : sans cette exception,
# git (donc Composer et Flex) refuse d'y travailler.
RUN git config --system --add safe.directory /app


# =============================================================================
# ci : exécution des tests fonctionnels avant merge request (à venir)
#   -> code copié (pas de bind mount) pour tester exactement ce qui serait
#      packagé. Dépendances dev incluses (phpunit, browser-kit...), pas de
#      Xdebug par défaut pour ne pas plomber les temps d'exécution.
# =============================================================================
FROM base AS ci

ENV APP_ENV=test

# Outils du service backup (compose.prod.yaml), qui tourne sur cette image :
# restic, supercronic pour le planifier, le client PostgreSQL de la même
# version majeure que le serveur (POSTGRES_VERSION, contrôlée par
# bin/prod-verify) et tzdata pour l'heure de Paris de supercronic.
ARG POSTGRES_VERSION=16
RUN apk add --no-cache \
        "postgresql${POSTGRES_VERSION}-client" \
        restic \
        supercronic \
        tzdata

COPY frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/
COPY . .

RUN composer install --prefer-dist --no-progress --no-interaction --optimize-autoloader


# =============================================================================
# prod : image de production, allégée et auto-suffisante
# =============================================================================
FROM base AS prod

# Mode worker : FrankenPHP garde le kernel Symfony en mémoire entre deux
# requêtes (symfony/runtime choisit FrankenPhpWorkerRunner quand FrankenPHP
# fixe FRANKENPHP_WORKER). Un service ne garde donc aucune donnée de requête,
# sinon il implémente ResetInterface (docs/architecture.md).
# FRANKENPHP_LOOP_MAX : chaque worker redémarre après 500 requêtes (le défaut
# de symfony/runtime, explicité ici), pour borner une fuite mémoire d'une
# dépendance. Un redémarrage coûte un démarrage du kernel, payé par une
# requête sur 500 : négligeable pour un dispositif qui interroge l'API toutes
# les 10 secondes.
ENV APP_ENV=prod \
    FRANKENPHP_CONFIG="worker /app/public/index.php" \
    FRANKENPHP_LOOP_MAX=500

# Outils du service backup (compose.prod.yaml), qui tourne sur cette image :
# restic, supercronic pour le planifier, le client PostgreSQL de la même
# version majeure que le serveur (POSTGRES_VERSION, contrôlée par
# bin/prod-verify) et tzdata pour l'heure de Paris de supercronic.
ARG POSTGRES_VERSION=16
RUN apk add --no-cache \
        "postgresql${POSTGRES_VERSION}-client" \
        restic \
        supercronic \
        tzdata

COPY frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/
COPY . .

RUN composer install --prefer-dist --no-dev --no-progress --no-interaction --optimize-autoloader \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console cache:clear --env=prod --no-warmup \
    && php bin/console tailwind:build --minify --env=prod \
    && php bin/console asset-map:compile --env=prod \
    && php bin/console cache:warmup --env=prod \
    && mkdir -p var/account-deletions \
    && chown -R www-data:www-data var /config/caddy /data/caddy

# FrankenPHP tourne sous www-data (doc FrankenPHP, « Running as a non-root
# user ») : l'image de base donne déjà au binaire la capacité de fichier
# cap_net_bind_service, qui lui garde le port 80, et www-data possède var/
# (cache, sessions, journal des suppressions) ainsi que les dossiers de Caddy
# (XDG_CONFIG_HOME et XDG_DATA_HOME de l'image). var/account-deletions/ existe
# dans l'image pour que le volume account_deletions de compose.prod.yaml
# reprenne son propriétaire au premier montage, au lieu d'être créé en root.
# Le chown est dans le RUN qui remplit var/ : dans
# un RUN à part, il recopierait tout var/ dans une nouvelle couche. Le dev et
# la CI restent en root (bind mount, Composer).

USER www-data
