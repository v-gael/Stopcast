# Supervision du serveur par Grafana Cloud

Statut : accepté.

La supervision en place prévient quand quelque chose casse : UptimeRobot appelle `/health`, Healthchecks.io attend le signal des tâches planifiées, Sentry reçoit les erreurs ([docs/exploitation.md](../exploitation.md#alertes)). Rien ne montre l'état du serveur et des conteneurs dans le temps : disque, mémoire, saturation de FrankenPHP, Redis face à sa limite. Le disque était à 82 % le 19 novembre 2026 sans qu'aucune alerte ne parte. Le serveur héberge plusieurs sites ([ADR 0006](0006-hebergement.md)) : le disque et la mémoire sont partagés, et chaque projet ne doit pas réinventer sa supervision. Le budget n'admet aucun service payant.

Nous envoyons les métriques du serveur à **Grafana Cloud**, sur l'offre gratuite, dans une pile créée en région **EU Germany** (AWS `eu-central-1`).

- **Un seul agent pour tout le serveur** : Grafana Alloy tourne dans `/srv/infra` (repo privé `v-gael/infra`), à côté de `cloudflared`. Il collecte les métriques de l'hôte et des conteneurs et les envoie par `remote_write`, en connexions sortantes seulement : aucun port ouvert, rien sur le tunnel.
- **Découverte par labels Docker** : un service qui expose des métriques porte le label `observability.metrics.port` et rejoint le réseau Docker interne `observability`, distinct de `edge`. Un port de métriques n'est donc jamais joignable par `cloudflared`. Stopcast expose celles de Caddy et FrankenPHP sur un port interne `:9180`, jamais sur `:80`. La convention pour les projets suivants (labels, réseau, port interne, préfixe `app_` des métriques propres à une application) est écrite dans le README du repo `infra`.
- **Pas de socket Docker dans Alloy** : il interroge un `docker-socket-proxy` en lecture seule, limité aux conteneurs et sans `POST`. Le socket donne l'équivalent d'un accès root.
- **cAdvisor** mesure la mémoire et le processeur de chaque conteneur. Il lit les cgroups et le dossier de Docker de l'hôte, en lecture seule : un accès large, accepté parce que c'est la seule mesure par conteneur, et que cAdvisor ne publie aucun port et n'est joignable que par Alloy.
- **Métriques seulement** : les logs restent sur le serveur, avec leur rétention actuelle ([docs/exploitation.md](../exploitation.md#rétention)).
- **Jeton en écriture seule** : l'access policy n'a que le scope `metrics:write`. Le jeton vit dans `/srv/infra/.env` ([docs/exploitation.md](../exploitation.md#jeton-grafana-cloud-grafana_cloud_token)).
- **Alertes par email** au mainteneur, depuis Grafana : disque plein, mémoire disponible basse, conteneur qui redémarre en boucle, serveur muet, FrankenPHP saturé.
- **UptimeRobot, Healthchecks.io et Sentry restent en place** : Grafana alerte sur ce qui se dégrade, eux sur ce qui est cassé, et UptimeRobot voit le site de l'extérieur, à travers Cloudflare.

L'offre gratuite ne coûte rien : le budget de l'[ADR 0006](0006-hebergement.md) ne change pas. Quotas relevés le 28 novembre 2026 :

| Poste | Offre gratuite |
|---|---|
| Métriques | 10 000 séries actives par mois, rétention 14 jours |
| Logs, traces | 50 Go ingérés par mois chacun, rétention 14 jours |
| Utilisateurs | 3 utilisateurs actifs par mois |
| Synthetic Monitoring | 100 000 tests d'API et 10 000 tests navigateur par mois |
| Tableaux de bord, dossiers | 1 000 chacun, limite stricte |
| Règles d'alerte | pas de quota publié pour l'offre gratuite ; valeurs par défaut : 35 groupes de règles, 20 règles par groupe |

## Options écartées

- **Pile auto-hébergée** (Grafana, Prometheus ou VictoriaMetrics, Loki) sur le serveur : elle prendrait une bonne part des 4 Go de mémoire, sans swap, partagés par tous les sites ; elle tomberait avec le serveur, donc sans alerte au moment où il en faut une ; et ce serait une interface web de plus à exposer et à maintenir.
- **Pile auto-hébergée sur un second serveur** : hors budget.
- **Envoyer aussi les logs** dès maintenant : ils contiennent des données personnelles (adresse IP tronquée, email masqué), ce qui ferait de Grafana Labs un sous-traitant de données personnelles, à analyser à part. L'envoi des logs sera une décision distincte.
- **Un agent par projet** : les métriques de l'hôte seraient collectées plusieurs fois, et chaque agent prendrait sa part de mémoire.
- **Socket Docker monté directement dans Alloy** : un agent qui parle à un service extérieur aurait un accès équivalent root au serveur.
- **Remplacer dès maintenant UptimeRobot et Healthchecks.io** par Grafana (Synthetic Monitoring, alertes sur l'absence de signal) : ils fonctionnent, et les retirer attendra que les alertes Grafana aient fait leurs preuves.

## Conséquences

- Grafana Labs est un sous-traitant, sans donnée personnelle, listé dans [docs/rgpd.md](../rgpd.md#sous-traitants).
- La supervision survit à une panne du serveur : son silence devient lui-même une alerte.
- Le quota de 10 000 séries actives se surveille : Alloy filtre les noms de métriques envoyés, et l'usage se lit dans le portail grafana.com (Cost management). La documentation publique ne dit pas ce qui arrive au-delà : la consommation réelle est relevée à la mise en route.
- 14 jours de rétention : pas de tendance plus longue, par exemple sur la croissance du disque au fil des mois.
- Un secret de plus, le seul à expirer : le jeton est changé chaque année ([docs/exploitation.md](../exploitation.md#jeton-grafana-cloud-grafana_cloud_token)).
- Un projet ajouté au serveur se fait superviser en suivant la convention du repo `infra`, sans toucher à Alloy.
- La supervision dépend de la pérennité de l'offre gratuite de Grafana Cloud et de ses quotas.

## Sources

Relevées le 2026-11-28 :

- [Grafana Cloud, Pricing](https://grafana.com/pricing/)
- [Grafana Cloud, Usage limits](https://grafana.com/docs/grafana-cloud/cost-management-and-billing/understand-your-invoice/usage-limits/)
- [Grafana Cloud, Regional availability](https://grafana.com/docs/grafana-cloud/security-and-account-management/regional-availability/)
