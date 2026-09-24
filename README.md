# Abribus

Réplique en temps réel l'affichage d'un panneau d'abribus physique, sur un petit écran connecté (Raspberry Pi), pour l'arrêt de bus de votre choix.

<p align="center">
  <em>Dans 3 min — Ligne 1 — Mairie</em><br>
  <em>Dans 7 min — Ligne 1 — Hôpital</em><br>
  <em>Dans 12 min — Ligne 4 — Gare Centrale</em>
</p>

## Le projet

La plupart des réseaux de transport publient deux flux de données ouvertes complémentaires :

- un flux **GTFS statique** (référentiel des lignes, arrêts, horaires théoriques)
- un flux **GTFS-RT** (position et retards en temps réel des véhicules)

Abribus croise les deux pour reconstituer, pour un arrêt physique donné, exactement ce qu'affiche le panneau d'information réel installé sur place — et le sert à un petit écran (480×320) piloté par un Raspberry Pi, pour un usage personnel : savoir d'un coup d'œil, depuis chez soi, quand partir attraper son bus.

Ce dépôt contient l'**API Symfony** : import du référentiel statique, exploitation du flux temps réel, exposition d'un endpoint consommé par le dispositif d'affichage, et administration des dispositifs/configurations via API Platform. Le client Raspberry Pi (Python) vit dans un dépôt séparé, [`abribus-display`](#).

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

1. **Import du référentiel statique** (`bin/console app:gtfs:import`), exécuté ponctuellement (à chaque changement d'horaires du réseau). Upsert sur les identifiants naturels GTFS (`stop_id`, `route_id`) pour ne jamais casser les configurations déjà enregistrées.
2. **Enregistrement du dispositif et sélection du/des quai(s)**, une fois, à la configuration initiale — via les endpoints CRUD `Device`/`DisplayConfiguration` (API Platform). Le libellé d'aide au choix (lignes desservies, destinations principales) est calculé à l'import pour rendre le sens de circulation intuitif sans jargon GTFS.
3. **En fonctionnement courant**, le Raspberry Pi interroge uniquement `GET /api/next-passages` avec son token dans le header `X-Device-Token` — pas d'identifiant dans l'URL. L'API résout le dispositif à partir du token, télécharge/parse le flux GTFS-RT une seule fois (mis en cache le temps de `GTFS_REALTIME_CACHE_TTL` secondes, partagé entre tous les devices), et renvoie pour chaque quai configuré (en rotation, dans l'ordre de `position`) les prochains passages (ligne, destination, couleurs, heure attendue).

## Stack technique

- **PHP 8.4 / Symfony 8.1**
- **API Platform 4** — CRUD `Device`/`DisplayConfiguration`, et endpoint métier `/api/next-passages` via un `Provider` dédié
- **Doctrine ORM** — entités `Quay`, `Route`, `Device`, `DisplayConfiguration`
- **`gtfs-media/gtfs-realtime-php`** — décodage du flux GTFS-RT (`Google\Transit\Realtime\FeedMessage`)
- **Zenstruck Foundry** (dev/test) — factories et fixtures (`Device`, `DisplayConfiguration`) construites sur les vrais `Quay` déjà importés
- Client d'affichage en **Python** sur Raspberry Pi (dépôt séparé)

## Modèle de données

| Entité | Rôle |
|---|---|
| `Quay` | Un quai GTFS (un sens de circulation précis à un arrêt physique) — import brut du référentiel statique |
| `Route` | Une ligne (numéro, couleurs d'affichage officielles du réseau) |
| `Device` | Un dispositif Raspberry Pi, identifié par un jeton d'authentification (`X-Device-Token`), activable/désactivable |
| `DisplayConfiguration` | La configuration d'affichage d'un `Device` : quel `Quay` afficher, sous quel libellé, dans quel ordre (`position`) en cas de rotation |

## API

CRUD complet sur `Device` et `DisplayConfiguration` exposé par API Platform (`/api/devices`, `/api/display_configurations`), pour l'administration des dispositifs.

Le Raspberry Pi, lui, n'appelle qu'un seul endpoint métier :

```
GET /api/next-passages
X-Device-Token: <token du device>
```

Renvoie, pour chaque `DisplayConfiguration` active du dispositif (triées par `position`), les prochains passages du quai correspondant. Pas de flag "flux indisponible" : si le GTFS-RT échoue, l'erreur remonte telle quelle plutôt qu'une réponse partielle — le Pi garde alors son dernier affichage valide.

### Variables d'environnement

| Variable | Rôle |
|---|---|
| `GTFS_STATIC_FEED_URL` | URL de l'archive GTFS statique à importer (`app:gtfs:import`) |
| `GTFS_REALTIME_TRIP_UPDATES_URL` | URL du flux GTFS-RT TripUpdates |
| `GTFS_REALTIME_CACHE_TTL` | Durée (s) de mise en cache partagée du flux RT téléchargé |
| `GTFS_REALTIME_NEXT_PASSAGES_LIMIT` | Nombre max de prochains passages renvoyés par quai |

## État du projet

Proof of concept fonctionnel : import du GTFS statique, exploitation du flux GTFS-RT, gestion multi-dispositifs (authentification par token `Device`, plusieurs `DisplayConfiguration` en rotation), endpoint `/api/next-passages`. Reste à finaliser l'affichage sur écran Raspberry Pi (dépôt séparé).

## Aller plus loin

- [`abribus-display`](#) — le client Raspberry Pi (Python)
