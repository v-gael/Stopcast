# Stopcast

Réplique en temps réel l'affichage du panneau d'un abri de bus sur un petit écran connecté (Raspberry Pi, tablette…), pour l'arrêt de bus de votre choix : savoir d'un coup d'œil, depuis chez soi, quand partir attraper son bus.

**En ligne :** [stopcast.gaelv.com](https://stopcast.gaelv.com)

<p align="center">
  <img src="docs/demo.gif" alt="Page d'affichage : rotation entre les quais Patton, Beurnonville et Champagne, avec leurs prochains passages" width="480">
  <img src="docs/afficheur-en-situation.jpg" alt="Photo de l'afficheur en situation : le panneau sur l'écran 3,5 pouces du Raspberry Pi" width="240">
</p>

<p align="center"><sub>À gauche, la page d'affichage servie par l'application, sur les vrais passages du réseau. À droite, l'afficheur en situation.</sub></p>

## En bref

Stopcast croise les deux flux de données ouvertes publiés par la plupart des réseaux de transport :

- le **GTFS statique** (lignes, arrêts, quais, destinations des trajets), importé en base ;
- le **GTFS-RT** (horaires en temps réel), interrogé et mis en cache à la volée.

Ce dépôt contient l'**application Symfony** qui reconstitue, pour un quai donné, ce qu'affiche le vrai panneau, et le sert sous forme de page d'affichage à tout écran doté d'un navigateur (Raspberry Pi, tablette…), appairé par un code.

L'essentiel du travail porte sur la modélisation GTFS : un arrêt se compose de plusieurs quais (un par sens), une ligne peut avoir plusieurs terminus, et les identifiants des flux statique et temps réel doivent concorder. Détails dans [docs/architecture.md](docs/architecture.md).

## Fonctionnement

```mermaid
flowchart LR
    ST["Référentiel statique<br/>(GTFS du réseau)"]
    RT["Flux temps réel<br/>(GTFS-RT du réseau)"]
    subgraph Back["Backend Symfony · Docker"]
        I["Import<br/>app:gtfs:import"] --> DB[("PostgreSQL<br/>Quay · Route · Trip")]
        DB --> A["GET /display"]
        C[("Cache partagé<br/>20 s")] --> A
    end
    ST -- "à chaque changement d'horaires" --> I
    RT -- "à la demande" --> C
    A -- "HTML, rafraîchi 1×/min<br/>(cookie de l'écran)" --> D["Dispositif<br/>(navigateur d'un écran : Raspberry Pi, tablette…)"]
```

## Choix techniques

Chaque décision structurante est consignée dans un ADR ([docs/adr/](docs/adr/)) :

- [Destination tirée du trajet statique](docs/adr/0001-destination-par-trajet.md) : le flux temps réel ne donne que le trajet (`trip_id`) ; la destination vient de son `trip_headsign` importé, l'heure uniquement du temps réel.
- [Passages masqués au terminus](docs/adr/0002-terminus-par-trajet.md) : un bus qui termine sa course au quai n'y est pas affiché, d'après le dernier arrêt de son trajet dans `stop_times.txt`.
- [Front en Twig](docs/adr/0003-front-twig.md) : l'espace web est rendu côté serveur (Twig, Form, Turbo, Tailwind sans Node) plutôt qu'en SPA. L'API qu'il laissait alors à l'écran a depuis disparu (ADR 0008).
- [Captcha Turnstile](docs/adr/0004-captcha-turnstile.md) sur la connexion et « mot de passe oublié », en plus des limites d'essais, avec un comportement défini quand Cloudflare ne répond pas.
- [Hébergement](docs/adr/0006-hebergement.md) : un VPS OVH, moins de 10 € par mois domaine compris, sans port entrant hors SSH, derrière Cloudflare Tunnel.
- [Envoi des mails par Brevo](docs/adr/0007-envoi-des-mails.md) : offre gratuite sans carte bancaire, DKIM aligné sur le domaine, DMARC.
- [Page d'affichage HTML et appairage par code](docs/adr/0008-affichage-html-et-appairage.md) : Stopcast fait la mise en page ; l'écran l'ouvre dans un navigateur en kiosque et s'authentifie par un cookie obtenu en saisissant un code, comme une télévision (RFC 8628). Elle remplace l'API JSON et le client Python.
- [Migrations expand/contract](docs/adr/0009-migrations-expand-contract.md) : une migration ne fait qu'ajouter, une suppression attend la release suivante, pour que l'ancien code tourne sur le nouveau schéma pendant un déploiement ou après un retour arrière.

Et dans la stack :

- **FrankenPHP** : PHP et Caddy dans une même image Docker, la même en développement, en CI et en production.
- **Qualité** : PHPStan au niveau 10, le plus strict, Rector et PHP CS Fixer ; PHPUnit, jusqu'à des tests dans Chrome headless (Panther) qui échouent sur toute erreur de console ou violation CSP. Les mêmes contrôles tournent en local (`make check`, hook `pre-push`) et en CI.
- **Livraison** : au merge sur `main`, l'image de prod est construite, scannée (Trivy), déployée par son SHA puis contrôlée depuis l'extérieur ; la CI pose ensuite le tag et publie la release.

## Stack

PHP 8.5 · Symfony 8.1 · Doctrine ORM · Twig · Turbo · Stimulus · Tailwind CSS · PostgreSQL 16 · FrankenPHP · Docker  
Qualité : PHPUnit, Panther, PHPStan niveau 10, PHP CS Fixer, Rector, Trivy, CI GitHub Actions.

## Démarrage rapide

```bash
cp .env .env.local   # renseigner les URLs GTFS
make up && make migrate && make import
```

## Documentation

- [Architecture et modèle de données](docs/architecture.md)
- [Variables d'environnement](docs/configuration.md)
- [Développement : installation, outils, conventions](docs/developpement.md)
- [Exploitation : déploiement et secrets de production](docs/exploitation.md)
- [Accessibilité : revue WCAG 2.2 AA](docs/accessibilite.md)
- [Données personnelles : registre des traitements et sous-traitants](docs/rgpd.md)

## État du projet

En production depuis novembre 2026 sur [stopcast.gaelv.com](https://stopcast.gaelv.com), pour mon propre usage ; les comptes y sont créés sur invitation.

- **Affichage** : import du référentiel GTFS, planifié en prod, et flux temps réel en cache partagé ; page d'affichage appairée par code, avec son aperçu en direct dans l'espace web.
- **Espace web** : dispositifs et configurations d'affichage (choix des quais, ordre de rotation), comptes sur invitation, administration des comptes, emails transactionnels ; connexion protégée par captcha, limites d'essais et refus des mots de passe ayant fuité.
- **Données personnelles** : export des données, suppression de son compte, purges automatiques selon les durées de conservation ([registre](docs/rgpd.md)).
- **Exploitation** : déploiement continu avec retour arrière, sauvegardes chiffrées hors du serveur, supervision et suivi des erreurs.

## Sources des données

Données : [TCAT – Troyes Champagne Métropole](https://transport.data.gouv.fr/datasets/donnees-tcat-troyes-champagne-metropole-1), [licence ODbL](https://opendatacommons.org/licenses/odbl/1.0/), via transport.data.gouv.fr.

Stopcast importe le référentiel statique de ce jeu de données (lignes, arrêts, quais, trajets) et interroge son flux temps réel pour afficher les prochains passages. L'application publie la même attribution sur sa page `/sources`, liée depuis le pied de page.

## Licence

[MIT](LICENSE)

Ressources tierces : la police [Roboto Condensed](https://github.com/googlefonts/roboto-classic) de la page d'affichage (`assets/fonts/`, instance statique au poids 700), © The Roboto Project Authors, est distribuée sous [SIL Open Font License 1.1](assets/fonts/OFL.txt).

---

<sub>Projet personnel, développé pour mon propre usage : les contributions ne sont pas attendues. Faille de sécurité : voir [SECURITY.md](SECURITY.md).</sub>
