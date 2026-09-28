# Passages masqués au terminus de leur trajet

À un quai qui sert de terminus, le flux GTFS-RT annonce aussi les bus qui y **terminent** leur course. Un vrai afficheur ne les montre pas : on ne peut pas y monter pour aller quelque part, et leur destination serait le nom du quai lui-même.

Nous enregistrons donc, pour chaque trajet (`Trip`), le `stop_id` de son dernier arrêt : celui du plus grand `stop_sequence` dans `stop_times.txt`. Un passage n'est pas affiché au quai qui est le terminus de son trajet. Si le trajet est inconnu du statique, ou n'a aucun horaire, le passage reste visible, comme pour la destination ([ADR 0001](0001-destination-par-trajet.md)).

Cela complète l'ADR 0001 : `stop_times.txt` sert désormais à connaître la **structure** du trajet (son terminus), mais toujours pas ses **horaires**. L'heure affichée vient uniquement du flux GTFS-RT.

## Options écartées

- **Heuristique temps réel** : considérer comme terminus un arrêt qui a une arrivée mais aucun départ. Vérifiée sur le flux actuel (48 cas sur 48), sans changement de schéma. Mais la spécification GTFS-RT autorise une arrivée seule sur un arrêt intermédiaire : si le producteur du flux change sa façon de faire, des bus en service disparaîtraient de l'écran.
- **`pickup_type` du GTFS statique** : il vaut 0 (montée possible) au terminus de chaque course de ce réseau, il ne permet donc pas de le repérer.
- **Destination égale au nom du quai** : fragile (homonymes, libellés différents).

## Conséquences

- Un import GTFS lit `stop_times.txt` une seconde fois, en flux, pour trouver le dernier arrêt de chaque trajet.
- Un trajet dont le temps réel ajoute ou supprime des arrêts en fin de course (détournement, trajet raccourci) garde son terminus théorique.
