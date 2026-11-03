# Hébergement sur un VPS OVH, derrière Cloudflare et Cloudflare Tunnel

Stopcast n'avait pas d'hébergement. Le serveur doit accueillir plusieurs sites (le portfolio sur `gaelv.com`, Stopcast en sous-domaine), pas seulement cette application, qui tourne en continu : un worker Messenger permanent, un import planifié et PostgreSQL. Le budget est plafonné à **10 €/mois TTC, domaine compris**, sans aucun service payant en plus.

Nous hébergeons sur un **VPS OVH VPS-1 2027** (2 vCores, 4 Go de RAM, 40 Go NVMe, trafic illimité à 500 Mbit/s) à Strasbourg, sans engagement, sous Ubuntu 26.04 LTS. Chaque projet y tourne avec Docker Compose, une stack par projet. PostgreSQL reste un conteneur de la stack, comme en local.

Le domaine `gaelv.com` est enregistré chez **Cloudflare Registrar** ; Stopcast est servi sur `stopcast.gaelv.com`. Cloudflare est devant le site pour le DNS, le proxy, le WAF géré gratuit, le cache des assets statiques, Turnstile et Email Routing (réception de `contact@gaelv.com`).

Le serveur n'ouvre aucun port entrant hors SSH : **Cloudflare Tunnel** relie Cloudflare au serveur. Le conteneur `cloudflared` établit le tunnel en sortie et route chaque sous-domaine vers le conteneur de son projet sur le réseau Docker `edge` (`172.30.0.0/24`). `cloudflared` et la création de ce réseau vivent dans le repo privé `v-gael/infra` ; le tunnel est géré depuis le tableau de bord Cloudflare.

Budget, prix relevés le 1er novembre 2026 :

| Poste | Prix | Par mois (TTC) |
|---|---|---|
| VPS OVH VPS-1 2027 | 4,49 € HT/mois, soit 5,39 € TTC, sans frais d'installation | 5,39 € |
| Domaine `gaelv.com` | 11,17 $/an payé le 2026-11-01, renouvellement au même prix (10,46 $ annoncés le 2026-10-30) | environ 0,80 € |
| **Total** | | **environ 6,15 €** |

## Options écartées

- **PaaS** (Render, Fly.io, Railway, Koyeb) : les offres gratuites mettent l'application en veille, Render supprime la base gratuite au bout de 30 jours, et chaque site, worker ou base se paie à part. Un worker permanent, un import planifié, PostgreSQL et plusieurs sites dépassent le budget.
- **Conteneurs managés** : même facturation par service que les PaaS, pour moins de contrôle qu'un VPS.
- **Hetzner CX23** (2 vCPU, 4 Go, 40 Go), choisi d'abord : en rupture de stock le 2026-11-01, comme toute la gamme Cost-Optimized, et plus cher (5,99 € HT/mois, soit 7,19 € TTC).
- **Base PostgreSQL gérée** : 10 à 15 €/mois, soit le budget entier à elle seule.
- **Domaine en `.fr`** : l'extension n'est pas vendue par Cloudflare Registrar.
- **DNS Cloudflare sans proxy** : l'adresse du serveur serait publique et il faudrait ouvrir 80/443, sans WAF ni cache devant.
- **Reverse proxy commun** (Caddy ou Traefik) avec les ports 80/443 ouverts : un service de plus à maintenir et une surface d'attaque exposée, que le tunnel rend inutiles.
- **Vault Symfony et secrets Docker** pour les secrets de prod : le vault met du texte chiffré dans un repo public et demande un commit par rotation ; `compose.prod.yaml` passe déjà tout par l'environnement. Les secrets restent dans un `.env.prod.local` par projet sur le serveur, lisible par son seul propriétaire.

## Conséquences

- OVH (hébergement) et Cloudflare (DNS, proxy, Tunnel, Turnstile) sont des sous-traitants, listés dans [docs/rgpd.md](../rgpd.md#sous-traitants).
- Le site dépend de la disponibilité de Cloudflare : sans lui, ni DNS ni tunnel.
- Cloudflare termine le TLS. L'application ne sert que du HTTP sur le réseau `edge` ; le mode TLS « Full (strict) » ne s'applique pas aux noms servis par le tunnel. Le HSTS est posé par Cloudflare, pas par l'application.
- L'application s'appuie sur les en-têtes envoyés par Cloudflare, en faisant confiance au réseau `edge` (`TRUSTED_PROXIES=172.30.0.0/24`). D'après la [documentation des en-têtes HTTP de Cloudflare](https://developers.cloudflare.com/fundamentals/reference/http-headers/) :
  - `X-Forwarded-For` vaut l'adresse IP du visiteur ; si le visiteur envoie déjà cet en-tête, Cloudflare ajoute son adresse **à la fin** de la liste. Symfony lit la chaîne de droite à gauche et s'arrête à la première adresse hors des proxies de confiance : il retient donc l'adresse ajoutée par Cloudflare, et une valeur forgée par le visiteur reste sans effet. Le [ticket cloudflared #1426](https://github.com/cloudflare/cloudflared/issues/1426) montre ce comportement à travers un tunnel (`X-Forwarded-For: a,b,c,…,203.0.113.1` reçu par l'origine).
  - `X-Forwarded-Proto` vaut le protocole utilisé par le visiteur pour joindre Cloudflare, `https` puisque « Always Use HTTPS » est activé ; une valeur envoyée par le visiteur est écrasée. Le [ticket cloudflared #1245](https://github.com/cloudflare/cloudflared/issues/1245) relève `x-forwarded-proto: https` reçu par l'origine à travers un tunnel.
  - La documentation de Cloudflare décrit ces en-têtes pour le trafic du proxy vers l'origine, sans section propre au tunnel ; ces deux comportements sont à vérifier sur l'application déployée.
- Il reste environ 3,85 €/mois sous le plafond, réservés aux sauvegardes (restic vers Cloudflare R2).
