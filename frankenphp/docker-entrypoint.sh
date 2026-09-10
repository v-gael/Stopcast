#!/bin/sh
set -e

# En dev, le code est monté en bind mount : vendor/ n'existe pas au premier
# démarrage (ou après un `composer.json` modifié côté host). On l'installe
# automatiquement pour éviter de devoir le faire manuellement.
if [ "$APP_ENV" = 'dev' ] && [ ! -d vendor ]; then
	composer install --prefer-dist --no-progress --no-interaction
fi

exec "$@"
