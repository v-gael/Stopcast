# Normes de code

Le code suit les [bonnes pratiques Symfony](https://symfony.com/doc/current/best_practices.html). Le style, le typage et la modernisation du code sont vérifiés par les outils de `make check` (voir [docs/developpement.md](docs/developpement.md#qualité-de-code)) : ce document ne liste que les règles qu'aucun outil ne vérifie et qu'une relecture doit contrôler.

## Règles

- **Attributs PHP pour les métadonnées du framework** (`#[Route]`, `#[IsGranted]`, `#[Assert\...]`, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`…), pas de routage YAML ou XML. La configuration reste à côté du code qu'elle décrit.
- **Autowiring d'abord.** Quand un type ne suffit pas, `#[Autowire]` (paramètre, variable d'environnement) ou `#[Target]` (choix d'une implémentation) dans la classe, avant toute définition de service en YAML, qui reste le dernier recours.
- **Contrôleurs fins** : ils étendent `AbstractController` et délèguent la logique aux services. Un service se teste et se réutilise sans requête HTTP.
- **`#[MapRequestPayload]` / `#[MapQueryString]`** sur les arguments d'action d'un contrôleur Symfony plutôt qu'un `json_decode()` ou un appel au Serializer à la main. La désérialisation et la validation sont alors faites par le framework.
- **DTO et objets-valeurs `readonly`**, avec promotion des propriétés du constructeur. Un service susceptible de devenir `lazy` n'est pas `readonly` : un proxy paresseux ne peut pas étendre une classe `readonly`.
- **`LockFactory` (`symfony/lock`) pour l'exclusion mutuelle**, jamais un drapeau ou un fichier de verrou fait main, qui casse sous la concurrence.
- **Composants Symfony avant une bibliothèque tierce ou une infrastructure faite main** (verrous, files, caches, clients HTTP, emails, planification). Un composant manquant s'installe avec `make composer c="require <paquet>"`, et sa recipe Flex écrit sa configuration.
- **Chaque feature a un test qui l'utilise comme un appelant le ferait** : une requête HTTP (`WebTestCase`) pour un contrôleur, un appel de service (`KernelTestCase`) pour un service. « Ça ne lève pas d'exception » ne suffit pas.
- **Gestes de test partagés dans `tests/Support/`** : un geste que plusieurs classes de test répètent (se connecter, lire un email, retrouver une ligne de liste) est une méthode d'un trait de `tests/Support/`, comme `WebTestTrait`, plutôt qu'une copie privée dans chaque classe.
- **Migrations Doctrine via `make migration`**, puis `make migrate`, jamais `doctrine:schema:update` ni de SQL écrit à la main, sauf la recopie de données ajoutée à une migration générée (expand/contract). Le schéma de chaque environnement reste reproductible.
- **Migrations expand/contract** : une migration ne fait qu'ajouter (colonne nullable ou avec une valeur par défaut, table, index). Supprimer, renommer, passer en `NOT NULL` ou ajouter une contrainte que l'ancien code pourrait violer n'arrive qu'à une release suivante, une fois en prod le code qui ne dépend plus de l'ancien schéma. L'ancien code tourne sur le nouveau schéma pendant chaque déploiement et après un retour arrière ([ADR 0009](docs/adr/0009-migrations-expand-contract.md)).
- **Registre et politique de confidentialité vont ensemble** : tout changement de fond sur les traitements ou les droits, dans `docs/rgpd.md` ou `templates/legal/privacy.html.twig`, se reporte sur l'autre, date de mise à jour comprise.
- **Une procédure d'exploitation se suit dans l'ordre** : dans `docs/exploitation.md`, ce qu'une étape utilise vient d'une étape précédente, et chaque résultat attendu dit quoi faire s'il diffère.
