# API

CRUD complet sur `Device` et `DisplayConfiguration` exposé par API Platform (`/api/devices`, `/api/display_configurations`), pour l'administration des dispositifs. Les quais et les lignes importés sont consultables en lecture seule (`/api/quays`, `/api/routes`), avec des filtres de recherche pour aider à choisir son quai.

> ⚠️ Ces endpoints d'administration ne sont pas encore protégés par une authentification : ne pas exposer l'API sur Internet en l'état.

La documentation OpenAPI interactive est disponible sur `/api` une fois l'application démarrée.

## Endpoint du dispositif d'affichage


Le Raspberry Pi, lui, n'appelle qu'un seul endpoint métier :

```
GET /api/next-passages
X-Device-Token: <token du device>
```

Renvoie, pour chaque `DisplayConfiguration` active du dispositif (triées par `position`), les prochains passages du quai correspondant. Pas de flag "flux indisponible" : si le GTFS-RT échoue, l'erreur remonte telle quelle plutôt qu'une réponse partielle — le Pi garde alors son dernier affichage valide.

Exemple de réponse : voir le [README](../README.md#exemple-de-réponse).

## Variables d'environnement

| Variable | Rôle |
|---|---|
| `GTFS_STATIC_FEED_URL` | URL de l'archive GTFS statique à importer (`app:gtfs:import`) |
| `GTFS_REALTIME_TRIP_UPDATES_URL` | URL du flux GTFS-RT TripUpdates |
| `GTFS_REALTIME_CACHE_TTL` | Durée (s) de mise en cache partagée du flux RT téléchargé |
| `GTFS_REALTIME_NEXT_PASSAGES_LIMIT` | Nombre max de prochains passages renvoyés par quai |
