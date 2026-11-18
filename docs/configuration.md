# Variables d'environnement

| Variable | Rôle |
|---|---|
| `GTFS_STATIC_FEED_URL` | URL de l'archive GTFS statique à importer (`app:gtfs:import`) |
| `GTFS_REALTIME_TRIP_UPDATES_URL` | URL du flux GTFS-RT TripUpdates |
| `GTFS_REALTIME_REFRESH_INTERVAL` | Période (s) du rafraîchissement de l'index des passages par `realtime-worker`, 20 par défaut. Seul ce service télécharge le flux RT ; une réponse illisible ne remplace jamais l'index (voir [exploitation.md](exploitation.md#index-temps-réel)) |
| `GTFS_REALTIME_MAX_AGE` | Âge maximal (s) du flux RT, d'après son `header.timestamp`, 300 par défaut. Au-delà, le flux est périmé et traité comme une panne ; un timestamp dans le futur compte comme frais. Un flux sans timestamp est frais au téléchargement ; l'index qui en est tiré prend l'âge de son rafraîchissement. `app:gtfs:realtime:check` affiche l'âge du flux servi |
| `GTFS_REALTIME_NEXT_PASSAGES_LIMIT` | Nombre max de prochains passages affichés par quai ; l'index en garde 3 fois plus, pour remplacer ceux qui partent entre deux rafraîchissements |
| `MAILER_DSN` | Transport des emails. `null://null` par défaut (rien ne part) ; en dev, `smtp://mailer:1025` (Mailpit) ; en prod, la clé SMTP de Brevo (`smtp://<login>:<clé>@smtp-relay.brevo.com:587`, voir [exploitation.md](exploitation.md#mails)). Obligatoire en prod |
| `MAILER_FROM` | Expéditeur de tous les emails, par défaut `Stopcast <no-reply@stopcast.local>` ; en prod, `Stopcast <no-reply@gaelv.com>`. Obligatoire en prod |
| `MAILER_REPLY_TO` | Adresse de réponse (`Reply-To`) de tous les emails, par défaut `contact@stopcast.local` ; en prod, `contact@gaelv.com`. Obligatoire en prod |
| `DEFAULT_URI` | Adresse publique du site (`https://domaine`), qui préfixe les liens des emails envoyés hors requête, comme l'avertissement d'inactivité de la purge nocturne. `http://localhost` par défaut |
| `MESSENGER_TRANSPORT_DSN` | Transport de la file `async` (mails), `doctrine://default?auto_setup=0` : table `messenger_messages` de la base |
| `SENTRY_ENVIRONMENT` | Environnement des événements envoyés à Sentry, `production` par défaut ; `prod-local` en prod locale |
| `TRUSTED_PROXIES`, `TRUSTED_HOSTS` | Proxys et hôtes de confiance : voir [developpement.md](developpement.md#proxy-et-hôtes-de-confiance) |
| `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` | Clés du captcha ; clés de test de Cloudflare par défaut (voir [developpement.md](developpement.md#comptes-utilisateurs)) |
| `REDIS_URL` | Redis de l'état partagé (voir [developpement.md](developpement.md#redis)) |
| `DATABASE_URL`, `APP_SECRET`, `SENTRY_DSN`, `HEALTHCHECKS_*` | Base, secrets et pings de la prod : voir [exploitation.md](exploitation.md#secrets) |
| `GITHUB_ADVISORIES_TOKEN` | Jeton de l'API GitHub pour `importmap:audit`, vide en local (voir [developpement.md](developpement.md#qualité-de-code)) |
