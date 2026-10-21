# syntax=docker/dockerfile:1

# =============================================================================
# base : dépendances système communes à toutes les stages
# =============================================================================
FROM dunglas/frankenphp:1-php8.5-alpine AS base

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apk add --no-cache \
        acl \
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

# En dev, /app est un bind mount qui n'appartient pas à root : sans cette
# exception, git (donc Composer et Flex) refuse d'y travailler.
RUN git config --system --add safe.directory /app

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


# =============================================================================
# ci : exécution des tests fonctionnels avant merge request (à venir)
#   -> code copié (pas de bind mount) pour tester exactement ce qui serait
#      packagé. Dépendances dev incluses (phpunit, browser-kit...), pas de
#      Xdebug par défaut pour ne pas plomber les temps d'exécution.
# =============================================================================
FROM base AS ci

ENV APP_ENV=test

COPY frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/
COPY . .

RUN composer install --prefer-dist --no-progress --no-interaction --optimize-autoloader


# =============================================================================
# prod : image de production, allégée et auto-suffisante
# =============================================================================
FROM base AS prod

ENV APP_ENV=prod

COPY frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/
COPY . .

RUN composer install --prefer-dist --no-dev --no-progress --no-interaction --optimize-autoloader \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console cache:clear --env=prod --no-warmup \
    && php bin/console tailwind:build --minify --env=prod \
    && php bin/console asset-map:compile --env=prod \
    && php bin/console cache:warmup --env=prod \
    && setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var \
    && setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var
