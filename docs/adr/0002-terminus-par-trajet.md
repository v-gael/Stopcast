# Passages masqués au terminus de leur trajet

À un quai qui sert de terminus, le flux GTFS-RT annonce aussi les bus qui y **terminent** leur course. Un vrai afficheur ne les montre pas : on ne peut pas y monter pour aller quelque part, et leur destination serait le nom du quai lui-même.

Nous enregistrons donc, pour chaque trajet (`Trip`), son terminus : le `stop_id` et le `stop_sequence` de son dernier arrêt, celui du plus grand `stop_sequence` dans `stop_times.txt`. Un terminus est un **passage**, pas seulement un quai : sur une ligne circulaire, le quai de départ est aussi le terminus, et seul le départ permet d'y monter. Un passage n'est donc pas affiché quand son quai et sa séquence (`stop_sequence` du StopTimeUpdate) sont ceux du terminus de son trajet ; le départ du même quai, en séquence 1, reste affiché.

Quand la séquence manque, dans le flux (le champ est facultatif en GTFS-RT, et TCAT l'omet sur certains arrêts ajoutés) ou en base (trajet importé avant l'enregistrement de la séquence), tout passage au quai terminus est masqué, sans regarder la séquence. Si le trajet est inconnu du statique, ou n'a aucun horaire, le passage reste visible, comme pour la destination ([ADR 0001](0001-destination-par-trajet.md)).

Cela complète l'ADR 0001 : `stop_times.txt` sert désormais à connaître la **structure** du trajet (son terminus), mais toujours pas ses **horaires**. L'heure affichée vient uniquement du flux GTFS-RT.

## Options écartées

- **Heuristique temps réel** : considérer comme terminus un arrêt qui a une arrivée mais aucun départ. Vérifiée sur le flux actuel (48 cas sur 48), sans changement de schéma. Mais la spécification GTFS-RT autorise une arrivée seule sur un arrêt intermédiaire : si le producteur du flux change sa façon de faire, des bus en service disparaîtraient de l'écran.
- **`pickup_type` du GTFS statique** : il vaut 0 (montée possible) au terminus de chaque course de ce réseau, il ne permet donc pas de le repérer.
- **Destination égale au nom du quai** : fragile (homonymes, libellés différents).
- **Terminus réduit au quai** : masquer tout passage au quai du dernier arrêt, sans regarder la séquence. C'était le choix initial. Sur une boucle, il masque le départ du quai d'origine : la navette N de TCAT, qui part de GARE JARDINS et y revient, n'y affichait aucun départ.
- **Masquer seulement si le quai n'apparaît qu'une fois dans le trajet** : sur une boucle, l'arrivée au quai d'origine réapparaîtrait. Sur la N, arrivées et départs tombent souvent à la même minute : chaque horaire serait affiché en double.
- **Repli par position quand la séquence manque** (masquer le passage seulement s'il est le dernier StopTimeUpdate du trajet) : TCAT annonce une boucle avant son départ avec un seul StopTimeUpdate, le départ du quai d'origine, qui est donc aussi le dernier. Le repli le masquerait quand même, pour un cas que la N, qui publie toujours `stop_sequence`, ne produit pas.
- **Garder la dernière occurrence d'un quai dans le trajet au lieu de la première** : sur une boucle, le départ serait perdu au profit de l'arrivée.

## Conséquences

- Un import GTFS trouve le dernier arrêt de chaque trajet, quai et séquence, pendant son unique lecture en flux de `stop_times.txt`, déjà nécessaire aux liens quai ↔ ligne.
- Tant qu'un import n'a pas renseigné la séquence (colonne `trip.terminus_stop_sequence` à `null`), le filtre reste celui du quai seul et les départs des boucles restent masqués.
- Un trajet dont le temps réel ajoute ou supprime des arrêts en fin de course (détournement, trajet raccourci) garde son terminus théorique.
