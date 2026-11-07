# Migrations en deux temps (expand/contract)

En prod, l'ancien code tourne sur le nouveau schéma dans deux cas :

- à chaque déploiement, `bin/deploy` passe les migrations avec la nouvelle image pendant que l'ancien code sert encore, puis remplace les conteneurs ([docs/exploitation.md](../exploitation.md#bindeploy)) : le temps du remplacement, jusqu'à 5 minutes pour le worker, remplacé seulement une fois `app` en bonne santé (`--wait-timeout 300`) ;
- après un retour arrière, `rollback <sha>` lance `bin/deploy --skip-migrations` : l'image remise tourne sur le schéma migré, jusqu'au déploiement suivant ([docs/exploitation.md](../exploitation.md#retour-arrière)).

Une migration qui supprime ou renomme une colonne que l'ancien code lit encore fait alors échouer ses requêtes. `Version20260929144502` l'aurait fait en prod : elle migre les données vers `deprecated_at` et supprime `quay.active` et `route.active` dans le même déploiement. Le seul repli, la restauration du dump pris avant migration, perd toutes les écritures faites depuis le début du déploiement, file Messenger comprise.

Les migrations suivent donc la règle **expand/contract** :

1. **Expand** : une migration ne fait qu'ajouter, sans casser le code en place : colonne nullable ou avec une valeur par défaut, table, index. Si des données doivent passer d'une colonne à l'autre, leur recopie s'ajoute à la migration générée par `make migration` : c'est la seule exception à « pas de SQL écrit à la main » de [CODING_STANDARDS.md](../../CODING_STANDARDS.md).
2. **Bascule** : le code de la même release lit et écrit le nouveau schéma, sans plus dépendre de l'ancien.
3. **Contract** : supprimer une colonne ou une table, renommer, passer une colonne en `NOT NULL` ou ajouter une contrainte que l'ancien code pourrait violer n'arrive qu'à une **release suivante**, une fois en prod le code de la bascule.

La règle s'applique aux migrations écrites après la première mise en prod. Celles qui existent avant passent toutes sur une base vide au premier déploiement, sans ancien code à casser : `Version20260929144502` reste telle quelle.

Les cas courants sont décrits dans [docs/developpement.md](../developpement.md#migrations). La règle est vérifiée en revue ([CODING_STANDARDS.md](../../CODING_STANDARDS.md)).

## Options écartées

- **Méthodes `down()` exécutées au retour arrière** : défaire une migration qui a supprimé des données ne les rend pas, et un `down()` jamais exécuté n'est jamais testé. Les migrations gardent le `down()` que génère le maker, mais ni le déploiement ni le retour arrière ne l'exécutent.
- **Suite de tests de la release précédente sur le nouveau schéma, en CI** : l'image n'est construite que sur `main` et celle de prod ne contient pas les tests. Il faudrait un second checkout de `develop`, les migrations de la branche, puis l'ancienne suite : un job complet de plus à chaque PR, pour un projet à un mainteneur dont chaque PR est relue.
- **Vérification automatique des migrations** (signaler `DROP`, `RENAME` ou `SET NOT NULL` dans celles qu'ajoute une branche, sauf marqueur de migration « contract ») : écartée pour l'instant, la revue suffit. À reprendre si elle laisse passer un cas.

## Conséquences

- Un changement de schéma destructif prend deux releases : la migration « contract » attend que la release précédente soit en prod.
- Le retour arrière ne garantit que le retour à la **release précédente** : elle supporte le schéma courant, mais une release plus ancienne peut dépendre de colonnes déjà retirées. Revenir plus loin, au-delà d'une migration « contract », demande la restauration du dump, selon la procédure de [docs/exploitation.md](../exploitation.md#si-lancien-code-ne-supporte-pas-le-nouveau-schéma).
- Des colonnes inutilisées restent en base le temps d'une release.
