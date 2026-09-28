# API

CRUD complet sur `Device` et `DisplayConfiguration` exposé par API Platform (`/api/devices`, `/api/display_configurations`), pour l'administration des dispositifs. Les quais et les lignes importés sont consultables en lecture seule (`/api/quays`, `/api/routes`), avec des filtres de recherche pour aider à choisir son quai.

## Authentification

Toute l'API, sauf `/api/next-passages`, exige la clé d'administration dans le header `Authorization` :

```
GET /api/devices
Authorization: Bearer <ADMIN_API_KEY>
```

Cela couvre l'administration (`/api/devices`, `/api/display_configurations`), le référentiel (`/api/quays`, `/api/routes`) et la documentation OpenAPI (`/api/docs.jsonopenapi`, `/api/docs.jsonld`). Sans clé, ou avec une mauvaise clé, la réponse est `401`. Si `ADMIN_API_KEY` est vide ou absente, l'administration est fermée : aucune clé n'est acceptée.

La clé est unique, sans comptes utilisateurs : pour la changer, modifier `ADMIN_API_KEY` et redémarrer le conteneur. La page Swagger UI (`/api` dans un navigateur) n'est plus consultable directement, un navigateur n'envoyant pas ce header ; récupérer plutôt la spécification OpenAPI avec la clé.

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
| `GTFS_REALTIME_CACHE_TTL` | Durée (s) de mise en cache partagée du flux RT téléchargé (une réponse illisible n'est jamais mise en cache : l'appel suivant retente le téléchargement) |
| `GTFS_REALTIME_NEXT_PASSAGES_LIMIT` | Nombre max de prochains passages renvoyés par quai |
| `ADMIN_API_KEY` | Clé d'administration de l'API (voir [Authentification](#authentification)) ; vide = administration fermée |
