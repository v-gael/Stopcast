# Variables d'environnement

| Variable | Rôle |
|---|---|
| `GTFS_STATIC_FEED_URL` | URL de l'archive GTFS statique à importer (`app:gtfs:import`) |
| `GTFS_REALTIME_TRIP_UPDATES_URL` | URL du flux GTFS-RT TripUpdates |
| `GTFS_REALTIME_CACHE_TTL` | Durée (s) de mise en cache partagée du flux RT téléchargé (une réponse illisible n'est jamais mise en cache : l'appel suivant retente le téléchargement) |
| `GTFS_REALTIME_NEXT_PASSAGES_LIMIT` | Nombre max de prochains passages retenus par quai |
| `MAILER_DSN` | Transport des emails. `null://null` par défaut (rien ne part) ; en dev, `smtp://mailer:1025` (Mailpit) ; en prod, le prestataire choisi au déploiement (`smtp://utilisateur:motdepasse@hôte:587` fonctionne sans paquet supplémentaire) |
| `MAILER_FROM` | Expéditeur de tous les emails, par défaut `Stopcast <no-reply@stopcast.local>` |
| `DEFAULT_URI` | Adresse publique du site (`https://domaine`), qui préfixe les liens des emails envoyés hors requête, comme l'avertissement d'inactivité de la purge nocturne. `http://localhost` par défaut |
