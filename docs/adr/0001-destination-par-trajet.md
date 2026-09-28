# Destination de chaque passage tirée du trajet statique

Le flux GTFS-RT ne donne, pour chaque passage, que le `trip_id` et le `route_id`, pas la destination. Afficher le nom long de la ligne donnait la même destination à tous ses bus, quel que soit leur sens ou leur terminus, ce qui contredit le principe d'un afficheur réaliste (voir [architecture](../architecture.md)).

Nous importons donc la correspondance `trip_id` → `trip_headsign` depuis `trips.txt` (entité `Trip`, remplacée intégralement à chaque import) et l'utilisons pour la destination de chaque passage. Nous gardons en revanche le principe de ne **pas** croiser le temps réel avec les horaires théoriques (`stop_times.txt`) : l'heure affichée vient uniquement du flux GTFS-RT.

Si le `trip_id` temps réel est inconnu du statique (trajet ajouté, décalage de version entre les deux flux) ou n'a pas de destination, on affiche le nom long de la ligne : le passage reste visible.

## Options écartées

- **Garder le nom long de la ligne** et corriger la documentation : plus simple, mais l'afficheur perdait l'information la plus utile au quai (quelle branche, quel sens).
- **Croiser aussi les horaires théoriques** : inutile pour la destination, et source de divergences avec le temps réel.

## Conséquences

- Le flux statique et le flux temps réel doivent partager les mêmes `trip_id`, en plus des `route_id` et `stop_id` : c'était déjà exigé (même source de données).
- Un import GTFS doit être relancé à chaque nouvelle version des horaires, sinon les nouveaux trajets s'affichent avec le nom de la ligne.
