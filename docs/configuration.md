# Variables d'environnement

| Variable | Rôle |
|---|---|
| `GTFS_STATIC_FEED_URL` | URL de l'archive GTFS statique à importer (`app:gtfs:import`) |
| `GTFS_REALTIME_TRIP_UPDATES_URL` | URL du flux GTFS-RT TripUpdates |
| `GTFS_REALTIME_CACHE_TTL` | Durée (s) de mise en cache partagée du flux RT téléchargé (une réponse illisible n'est jamais mise en cache : l'appel suivant retente le téléchargement) |
| `GTFS_REALTIME_MAX_AGE` | Âge maximal (s) du flux RT, d'après son `header.timestamp`, 300 par défaut. Au-delà, le flux est périmé et traité comme une panne ; un timestamp absent (0) ou dans le futur compte comme frais. `app:gtfs:realtime:check` affiche l'âge du flux servi |
| `GTFS_REALTIME_NEXT_PASSAGES_LIMIT` | Nombre max de prochains passages retenus par quai |
| `MAILER_DSN` | Transport des emails. `null://null` par défaut (rien ne part) ; en dev, `smtp://mailer:1025` (Mailpit) ; en prod, les identifiants SMTP d'AhaSend (`smtp://<login>:<mot de passe>@send.ahasend.com:587`, voir [exploitation.md](exploitation.md#mails)). Obligatoire en prod |
| `MAILER_FROM` | Expéditeur de tous les emails, par défaut `Stopcast <no-reply@stopcast.local>` ; en prod, `Stopcast <no-reply@gaelv.com>`. Obligatoire en prod |
| `MAILER_REPLY_TO` | Adresse de réponse (`Reply-To`) de tous les emails, par défaut `contact@stopcast.local` ; en prod, `contact@gaelv.com`. Obligatoire en prod |
| `DEFAULT_URI` | Adresse publique du site (`https://domaine`), qui préfixe les liens des emails envoyés hors requête, comme l'avertissement d'inactivité de la purge nocturne. `http://localhost` par défaut |
