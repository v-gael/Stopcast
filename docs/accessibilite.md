# Accessibilité

## Référentiel

Revue au regard du [WCAG 2.2](https://www.w3.org/TR/WCAG22/), niveau AA. Le RGAA ne s'impose pas à un projet de particulier et n'est pas revendiqué. La revue des templates ne remplace pas une passe dans un navigateur : tant que la passe manuelle (plus bas) n'est pas faite, l'interface est au mieux **partiellement conforme**.

## Pages revues

| Espace | Pages | Templates |
|---|---|---|
| Public | accueil et connexion, mot de passe oublié, email envoyé, nouveau mot de passe, activation d'un compte invité, pages d'erreur (403, 404, autres) | `home/`, `reset_password/`, `invitation/`, `bundles/TwigBundle/Exception/` |
| Connecté | mes dispositifs, ajout, fiche et modification d'un dispositif, configurations d'affichage (ajout, modification), mon compte, changement de mot de passe, suppression du compte | `device/`, `display_configuration/`, `account/` |
| Administration | utilisateurs, invitation, modification d'un compte | `admin/user/` |
| Communs | layouts, composants, thème de formulaire | `base.html.twig`, `layout/`, `components/`, `form/tailwind_layout.html.twig`, `assets/styles/app.css` |
| Écran | page d'affichage, écran d'appairage | `display/`, `assets/styles/display.css` |

La page d'affichage n'est revue que pour les contrastes et la lisibilité, puisqu'elle n'est pas interactive : voir « Page d'affichage » plus bas. Les emails (`templates/email/`) sont hors périmètre.

## Méthode

- Lecture de chaque template et du thème de formulaire de Symfony (`vendor/symfony/twig-bridge/Resources/views/Form/`) au regard des critères : lien d'évitement (2.4.1), plan des titres (1.3.1, 2.4.6), libellés et erreurs reliés aux champs (1.3.1, 3.3.1, 3.3.2, 4.1.2), focus visible (2.4.7, 1.4.11), intitulés des liens et boutons (2.4.4, 2.5.3), messages d'état (4.1.3), tableaux (1.3.1), icônes décoratives (1.1.1), taille des cibles (2.5.8), contrastes (1.4.3, 1.4.11), usage de la couleur (1.4.1).
- Contrastes calculés à partir des couleurs de Tailwind 4.3.3 (`theme.css`, en `oklch`) converties en sRGB, puis formule de luminance relative du WCAG. Le fond d'une couleur à opacité réduite est mélangé avant calcul.
- Ce que le HTML rendu permet de vérifier est couvert par `tests/Functional/Web/AccessibilityTest.php` : lien d'évitement en tête des deux layouts, un seul `h1` et aucun niveau sauté, un `<label>` relié à chaque champ, l'aide et les erreurs d'un champ en erreur dans son `aria-describedby`, groupe de boutons radio nommé.

## Écarts corrigés

- **Lien d'évitement** : « Aller au contenu », premier lien de chaque page (`base.html.twig`), masqué hors focus, vers `<main id="contenu">` des deux layouts.
- **Titres** : les pages publiques à carte (mot de passe oublié, email envoyé, nouveau mot de passe, activation) n'avaient pas de `h1` ; les étapes de l'accueil étaient des `h2` sous un `h2`. Le composant `Card` prend un `titleLevel` (2 par défaut).
- **Erreurs de formulaire** : le thème ajoutait `_error1` à l'`aria-describedby` que `form_row` remplit déjà (aide et toutes les erreurs) : la référence apparaissait deux fois. Il complète maintenant sans doublon, ce qui relie aussi les erreurs d'une case à cocher (`checkbox_row` de Symfony ne relie que l'aide).
- **Groupes de boutons radio** (rôle d'un compte) : le libellé du champ n'a pas de `for` ; le groupe porte `role="radiogroup"` et `aria-labelledby` vers ce libellé.
- **Focus visible** : contour amber-500 (2,13:1 sur blanc) remplacé par amber-700 sur les boutons et les flèches de rotation, idem pour l'anneau des champs et de Tom Select ; règle `:focus-visible` par défaut en amber-700 pour les liens qui n'en avaient pas ; dans l'en-tête sombre de l'espace, contour amber-400 sur les liens et le bouton de déconnexion.
- **Contrastes** : liens d'action amber-600 (3,20:1) passés en amber-700, survols sous 4,5:1 (amber-600, amber-500, red-500) assombris ; survol du bouton danger red-500 (3,81:1 sous du blanc) passé en red-700 ; bordures des champs et des cases slate-300 (1,49:1) passées en slate-500.
- **Liens dans le texte** : « Ajoutez-en un » (configurations) et le nom du dispositif (modification d'une configuration) n'étaient distingués que par la couleur et la graisse : soulignés.
- **Intitulés** : les boutons répétés dans une liste nomment leur objet pour les lecteurs d'écran (« Modifier », « Supprimer », « Monter », « Descendre » d'une configuration ; « Ajouter un quai à … ») ; le lien « GitHub » de l'en-tête public dit ce qu'il ouvre ; l'explication du badge « Quai disparu », jusqu'ici seulement dans un `title`, est aussi en texte masqué.
- **Cibles de 24 px** : liens de la navigation de l'espace et actions du tableau des utilisateurs portés à 24 px de haut.

Déjà conformes, sans changement : `lang="fr"`, titres de page distincts, landmarks (`header`, `nav` nommée, `main`, `footer`), `aria-current` dans la navigation, flashs en `role="status"` (succès) ou `role="alert"` (erreur, avertissement), encadré d'erreurs globales en `role="alert"`, en-têtes de tableau `scope="col"` (colonne d'actions nommée en texte masqué), icônes et émoji en `aria-hidden`, images d'accueil avec un `alt`, `autocomplete` sur les champs d'email et de mot de passe de l'utilisateur, bouton de fermeture des toasts de 24 px, flèches de rotation de 28 px.

## Écarts restants

| Point | Critère | Raison |
|---|---|---|
| Messages flash affichés avec la page, après une navigation Turbo | 4.1.3 | Un `role="status"` ou `role="alert"` présent dès le rendu n'est pas toujours annoncé ; à vérifier au lecteur d'écran avant de choisir une région live permanente. |
| Navigation Turbo : annonce de la nouvelle page et position du focus | 2.4.3 | Comportement de Turbo Drive, invisible dans le HTML : passe manuelle. |
| Toasts fixes en bas à droite de l'espace | 2.4.11 | Un toast d'erreur reste jusqu'à « Fermer » et peut recouvrir en partie un élément focalisé en bas de page ; à mesurer dans un navigateur. |
| Champ « Quai » (Tom Select) | 1.3.1, 4.1.2 | Tom Select remplace le `<select>` par son propre champ : le libellé, l'aide et les erreurs doivent être vérifiés sur le champ généré. |
| Bordure des boutons secondaires (slate-300, 1,49:1) | 1.4.11 | Non requise : le bouton est identifié par son texte, et la bordure ne sert pas à le repérer. Idem pour l'anneau des flèches de rotation, identifiées par leur icône (4,76:1). |
| Badges (anneaux de couleur claire) | 1.4.11 | Décoratifs : le texte du badge porte l'information et atteint 4,5:1. |
| Cases à cocher de 16 px | 2.5.8 | Exception d'espacement : aucune autre cible dans un cercle de 24 px, et le libellé, cliquable, agrandit la zone. |
| Texte grisé des champs désactivés (slate-500 sur slate-100, 4,35:1) | 1.4.3 | Exemption des composants inactifs. |
| Badge « Quai disparu » de la page de modification d'un quai | 4.1.2 | L'explication est dans le `title` et en texte masqué : certains lecteurs d'écran pourraient la lire deux fois. |

## Contrastes calculés

Couleurs Tailwind 4.3.3 converties en sRGB. Seuils : 4,5:1 pour le texte, 3:1 pour le grand texte, les icônes, les bordures de champ et les indicateurs de focus.

| Usage | Texte ou élément | Fond | Rapport | Seuil |
|---|---|---|---|---|
| Texte courant de la page | slate-800 `#1d293d` | slate-50 `#f8fafc` | 13,97 | 4,5 |
| Titres, texte des cartes | slate-900 `#0f172b` | blanc | 17,83 | 4,5 |
| Libellés de champ | slate-800 `#1d293d` | blanc | 14,62 | 4,5 |
| Paragraphes | slate-600 `#45556c` | blanc | 7,58 | 4,5 |
| Paragraphes de l'accueil | slate-600 `#45556c` | slate-50 `#f8fafc` | 7,25 | 4,5 |
| Aide des champs, pied de page, `dt`, « (vous) », état vide de Tom Select | slate-500 `#62748e` | blanc | 4,76 | 4,5 |
| En-têtes de tableau | slate-700 `#314158` | slate-50 `#f8fafc` | 9,90 | 4,5 |
| Badge neutre | slate-700 `#314158` | slate-100 `#f1f5f9` | 9,45 | 4,5 |
| Badge succès | emerald-800 `#006045` | emerald-50 `#ecfdf5` | 7,23 | 4,5 |
| Badge avertissement | amber-800 `#973c00` | amber-50 `#fffbeb` | 6,84 | 4,5 |
| Flash et encadré d'erreur | red-800 `#9f0712` | red-50 `#fef2f2` | 7,64 | 4,5 |
| Erreur sous un champ | red-700 `#c10007` | blanc | 6,42 | 4,5 |
| État d'un dispositif : neutre, avertissement, hors ligne, en ligne | slate-600 `#45556c`, amber-700 `#bb4d00`, red-700 `#c10007`, emerald-700 `#007a55` | blanc | 7,58, 5,03, 6,42, 5,37 | 4,5 |
| Option active de Tom Select | slate-900 `#0f172b` | amber-50 `#fffbeb` | 17,19 | 4,5 |
| En-tête de l'espace | blanc | slate-900 `#0f172b` | 17,83 | 4,5 |
| Survol et focus dans l'en-tête de l'espace | amber-400 `#ffb900` | slate-900 `#0f172b` | 10,35 | 4,5 |
| Bouton principal | slate-900 `#0f172b` | amber-500 `#fe9a00` | 8,35 | 4,5 |
| Bouton principal survolé | slate-900 `#0f172b` | amber-400 `#ffb900` | 10,35 | 4,5 |
| Bouton danger | blanc | red-600 `#e7000b` | 4,77 | 4,5 |
| Bouton danger survolé | blanc | red-700 `#c10007` | 6,42 | 4,5 |
| Liens d'action, « Mot de passe oublié ? » | amber-700 `#bb4d00` | blanc | 5,03 | 4,5 |
| Liens survolés | amber-800 `#973c00` | blanc | 7,09 | 4,5 |
| « Erreur 404 » des pages d'erreur | amber-700 `#bb4d00` | slate-50 `#f8fafc` | 4,81 | 4,5 |
| Lien « Supprimer » d'un compte | red-600 `#e7000b` | blanc | 4,77 | 4,5 |
| Lien « Supprimer » survolé | red-800 `#9f0712` | blanc | 8,36 | 4,5 |
| Focus (contour par défaut, boutons, champs) | amber-700 `#bb4d00` | blanc | 5,03 | 3 |
| Focus du bouton secondaire | slate-500 `#62748e` | blanc | 4,76 | 3 |
| Bordure des champs et des cases | slate-500 `#62748e` | blanc | 4,76 | 3 |
| Case cochée | amber-600 `#e17100` | blanc | 3,20 | 3 |
| Icônes des flèches de rotation | slate-500 `#62748e` | blanc | 4,76 | 3 |
| Croix de fermeture d'un toast (opacité 70 %) | emerald-800, amber-800, red-800 | emerald-50, amber-50, red-50 | 3,64, 3,60, 4,40 | 3 |
| Ancien focus (corrigé) | amber-500 `#fe9a00` | blanc | 2,13 | 3 |
| Anciens liens d'action (corrigés) | amber-600 `#e17100` | blanc | 3,20 | 4,5 |
| Ancien survol du bouton danger (corrigé) | blanc | red-500 `#fb2c36` | 3,81 | 4,5 |
| Anciennes bordures des champs (corrigées) | slate-300 `#cad5e2` | blanc | 1,49 | 3 |

## Page d'affichage

La page d'affichage reprend les couleurs de l'ancien client Python (`assets/styles/display.css`). Les tailles se mesurent en `--u`, qui vaut un pixel sur l'écran de référence de 480×320 et grandit avec l'écran : les seuils ci-dessous sont lus à cette taille, la plus petite.

| Usage | Texte | Fond | Rapport | Seuil |
|---|---|---|---|---|
| Nom du quai, heure, délais, destinations, messages | blanc | `#05203c` | 16,43 | 4,5 |
| En-têtes de colonnes (gras, 20 `--u`) | `#7baee2` | `#1b4872` | 4,06 | 3 (grand texte) |
| Pastille d'une ligne sans couleur dans les données du réseau | blanc | `#1b4872` | 9,47 | 4,5 |
| Consigne de l'écran d'appairage (gras, 14 `--u`) | `#7baee2` | `#05203c` | 7,04 | 4,5 |

**Pastilles de ligne** : elles reprennent `route_color` et `route_text_color` du réseau telles quelles, comme les vrais panneaux et les plans du réseau, pour que chaque ligne se reconnaisse à sa couleur. C'est un écart assumé : calculé le 7 novembre 2026 sur les 61 lignes TCAT, en texte gras de 16 `--u` (seuil 4,5:1), 14 lignes sont en dessous, comme la ligne 1 (blanc sur `#009DE0`, 3,04:1) ou la 7 (blanc sur `#919294`, 3,11:1), et la 9A (blanc sur `#ED86A6`) descend à 2,45:1. Le numéro de ligne est aussi écrit, et la destination est en blanc sur le fond de l'écran : la couleur n'est pas le seul repère. Un choix automatique du texte noir ou blanc quand le contraste est trop faible pourra être décidé avec les thèmes d'affichage.

## Lighthouse

Mesure ponctuelle du 9 novembre 2026 sur la production, derrière Cloudflare, avec Lighthouse 13.6 en ligne de commande. Cible : 90 dans chaque catégorie.

| Page | Appareil | Performance | Accessibilité | Bonnes pratiques | SEO |
|---|---|---|---|---|---|
| Accueil et connexion (`/`) | mobile | 96 | 100 | 100 | 100 |
| Accueil et connexion (`/`) | desktop | 100 | 100 | 100 | 100 |
| Mon compte (`/account`) | mobile | 97 | 100 | 100 | 63 |
| Mon compte (`/account`) | desktop | 100 | 100 | 100 | 63 |
| Fiche d'un dispositif (`/devices/1`) | mobile | 100 | 100 | 100 | 63 |
| Fiche d'un dispositif (`/devices/1`) | desktop | 100 | 100 | 100 | 63 |
| Écran d'appairage (`/display`, écran non appairé) | desktop | 100 | 97 | 100 | 54 |
| Page d'affichage (`/display`, écran appairé) | desktop | 100 | 96 | 100 | 54 |

Le SEO sous 90 est un écart voulu : ces pages portent volontairement `noindex` (`templates/base.html.twig`, `templates/display/`), et seul l'accueil est indexé. La cible de 90 en SEO ne vaut donc que pour l'accueil. Le 63 vient du seul audit `is-crawlable` ; le 54 des deux pages de `/display` y ajoute `meta-description`, sans objet sur une page non indexée.

`/display` remplit un écran en paysage : seule la mesure desktop y est représentative. Son accessibilité perd quelques points sur l'audit `landmark-one-main`, ces pages n'ayant pas d'élément `<main>`.

## Passe manuelle
