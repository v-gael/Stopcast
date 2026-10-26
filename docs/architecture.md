# Architecture

## Pourquoi ce projet

Au-delà de l'affichage lui-même (relativement simple), l'essentiel du travail porte sur la modélisation des données GTFS :

- **un arrêt de bus n'est pas un point unique.** Il se compose en général de plusieurs quais, un par sens de circulation — parfois plus de deux sur les pôles d'échange. Le référentiel doit permettre à l'utilisateur de sélectionner précisément *son* quai, sans jamais lui exposer les identifiants techniques (`direction_id`, `stop_id`...) sous-jacents.
- **une ligne peut avoir plusieurs terminus réels** sur un même quai (branches en fin de parcours) : la destination affichée varie donc passage par passage, exactement comme sur un vrai panneau — on ne peut pas la figer à la configuration.
- **le flux temps réel et le référentiel statique doivent provenir de la même source de données**, sous peine de faire correspondre les mauvais identifiants entre les deux (une ligne "1" dans un flux peut très bien être la ligne "4" dans un autre référentiel du même réseau).

## Fonctionnement

```
┌─────────────────────┐      ┌─────────────────┐      ┌───────────────────┐
│   GTFS statique     │      │   GTFS-RT (RT)  │      │   Raspberry Pi    │
│  (import ponctuel,  │      │  (récupéré à    │      │  (script Python,  │
│   CLI Symfony)      │      │   intervalle    │      │   écran 480x320)  │
└──────────┬──────────┘      │   régulier)     │      └──────────┬────────┘
           │                 └─────────┬───────┘                 │
           ▼                           │                         │
   ┌────────────────┐                  │                         │
   │  Base de       │                  │                         │
   │  données       │◄─────────────────┘                         │
   │  (Quay, Route) │                                            │
   └───────┬────────┘                                            │
           │                                                     │
           ▼                                                     │
   ┌───────────────────────────────────┐                         │
   │  API Symfony (endpoint prochains  │◄────────────────────────┘
   │  passages pour un Device donné)   │   GET /api/next-passages
   └───────────────────────────────────┘        (header X-Device-Token)
```

1. **Import du référentiel statique** (`bin/console app:gtfs:import`, ou `make import`), exécuté ponctuellement (à chaque changement d'horaires du réseau). Upsert sur les identifiants naturels GTFS (`stop_id`, `route_id`) pour ne jamais casser les configurations déjà enregistrées.
2. **Enregistrement du dispositif et sélection du/des quai(s)**, une fois, à la configuration initiale — depuis l'espace web, par le propriétaire du dispositif (voir [ADR 0003](adr/0003-front-twig.md)). Le jeton du dispositif n'y est affiché qu'une fois, à sa création ou à sa régénération. Le libellé d'aide au choix (lignes desservies, destinations principales) est calculé à l'import pour rendre le sens de circulation intuitif sans jargon GTFS.
3. **En fonctionnement courant**, le Raspberry Pi interroge uniquement `GET /api/next-passages` avec son token dans le header `X-Device-Token` — pas d'identifiant dans l'URL. L'API résout le dispositif à partir du token, télécharge/parse le flux GTFS-RT une seule fois (mis en cache le temps de `GTFS_REALTIME_CACHE_TTL` secondes, partagé entre tous les devices), et renvoie pour chaque quai configuré (en rotation, dans l'ordre de `position`) les prochains passages (ligne, destination, couleurs, heure attendue), sans les bus qui terminent leur course à ce quai.

## Modèle de données

| Entité | Rôle |
|---|---|
| `Quay` | Un quai GTFS (un sens de circulation précis à un arrêt physique) — import brut du référentiel statique |
| `Route` | Une ligne (numéro, couleurs d'affichage officielles du réseau) |
| `Trip` | Un trajet du référentiel statique, retrouvé par le `trip_id` du flux temps réel et conservé uniquement pour sa destination (`trip_headsign`) et son terminus, où ses passages ne sont pas affichés — voir [ADR 0001](adr/0001-destination-par-trajet.md) et [ADR 0002](adr/0002-terminus-par-trajet.md) |
| `User` | Un compte de l'espace web (email, mot de passe haché, rôle `ROLE_USER` ou `ROLE_ADMIN`) ; en attente tant qu'un invité n'a pas choisi son mot de passe, actif ensuite |
| `Device` | Un dispositif Raspberry Pi, identifié par un jeton d'authentification (`X-Device-Token`), activable/désactivable, qui appartient à un `User` (son propriétaire) |
| `DisplayConfiguration` | La configuration d'affichage d'un `Device` : quel `Quay` afficher, sous quel libellé, dans quel ordre (`position`) en cas de rotation |
