# API

L'API ne sert que le dispositif d'affichage : un seul endpoint, `GET /api/next-passages`, exposé par API Platform. La gestion des dispositifs et de leurs quais se fait depuis l'espace web, avec un compte utilisateur (voir [ADR 0003](adr/0003-front-twig.md)).

La documentation OpenAPI est publique : Swagger UI sur `/api` dans un navigateur, spécification sur `/api/docs.jsonopenapi`.

## Endpoint du dispositif d'affichage

Le Raspberry Pi appelle :

```
GET /api/next-passages
X-Device-Token: <token du device>
```

Renvoie, pour chaque `DisplayConfiguration` active du dispositif (triées par `position`), les prochains passages du quai correspondant. Pas de flag "flux indisponible" : si le GTFS-RT échoue, l'erreur remonte telle quelle plutôt qu'une réponse partielle — le Pi garde alors son dernier affichage valide.

Un token absent, inconnu ou d'un dispositif désactivé renvoie `401` (`Token manquant.` ou `Token invalide ou dispositif désactivé.`), sans header `WWW-Authenticate`. Les accès sont cloisonnés : une session d'utilisateur connecté n'ouvre pas cet endpoint, et le token d'un dispositif n'ouvre pas l'espace web.

Exemple de réponse : voir le [README](../README.md#exemple-de-réponse).

## Variables d'environnement

| Variable | Rôle |
|---|---|
| `GTFS_STATIC_FEED_URL` | URL de l'archive GTFS statique à importer (`app:gtfs:import`) |
| `GTFS_REALTIME_TRIP_UPDATES_URL` | URL du flux GTFS-RT TripUpdates |
| `GTFS_REALTIME_CACHE_TTL` | Durée (s) de mise en cache partagée du flux RT téléchargé (une réponse illisible n'est jamais mise en cache : l'appel suivant retente le téléchargement) |
| `GTFS_REALTIME_NEXT_PASSAGES_LIMIT` | Nombre max de prochains passages renvoyés par quai |
| `MAILER_DSN` | Transport des emails. `null://null` par défaut (rien ne part) ; en dev, `smtp://mailer:1025` (Mailpit) ; en prod, le prestataire choisi au déploiement (`smtp://utilisateur:motdepasse@hôte:587` fonctionne sans paquet supplémentaire) |
| `MAILER_FROM` | Expéditeur de tous les emails, par défaut `Abribus <no-reply@abribus.local>` |
