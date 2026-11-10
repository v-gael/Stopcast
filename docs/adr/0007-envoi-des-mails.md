# Envoi des mails par Brevo, depuis `gaelv.com`

Stopcast envoie des mails transactionnels : invitations, liens de réinitialisation du mot de passe, avis liés au compte. Ils ne demandent pas de lien de désinscription, mais ils doivent arriver en boîte de réception. En prod, `MAILER_DSN` valait `null://null` : aucun mail ne partait. Le budget n'admet aucun service payant ([ADR 0006](0006-hebergement.md)), et Cloudflare Email Routing, qui reçoit `contact@gaelv.com`, ne sait pas envoyer.

Nous envoyons par **Brevo**, sur l'offre gratuite **sans carte bancaire** : 300 mails par jour. Sans carte, un dépassement du quota bloque l'envoi au lieu d'être facturé.

- **Domaine** : les mails partent de la racine `gaelv.com`. Expéditeur `Stopcast <no-reply@gaelv.com>` (`MAILER_FROM`), sans boîte derrière ; réponses vers `contact@gaelv.com` (`MAILER_REPLY_TO`, en-tête `Reply-To`), reçue par Email Routing.
- **Transport** : le DSN SMTP natif de Symfony (`smtp://…@smtp-relay.brevo.com:587`) avec une **clé SMTP**, qui ne sert qu'à envoyer. Aucun paquet en plus.
- **Authentification** : DKIM de Brevo, aligné sur `gaelv.com`. Le SPF de la racine reste celui d'Email Routing : Brevo n'en demande pas, et le DMARC passe par le DKIM aligné. DMARC `p=none` d'abord, car Brevo n'authentifie pas le domaine sans politique publiée, puis `p=reject` après un test réussi, rapports vers Cloudflare DMARC Management.

La configuration et les enregistrements DNS sont décrits dans [docs/exploitation.md](../exploitation.md#mails).

## Options écartées

- **Scaleway TEM** : carte bancaire obligatoire, et un dépassement est facturé.
- **Cloudflare Email Service** : l'envoi est réservé à l'offre Workers Paid, 5 $/mois.
- **Postmark** et **Amazon SES** : hors de l'UE, ou sans offre gratuite durable.
- **Sous-domaine d'envoi** : `stopcast.gaelv.com` est un CNAME du tunnel et ne peut pas porter d'enregistrement TXT.
- **Clé API avec `symfony/brevo-mailer`** : une clé API donne accès à tout le compte Brevo si elle fuit, la clé SMTP seulement à l'envoi.
- **Rester en DMARC `p=none` pour une période d'observation** : un seul expéditeur sur le domaine, vérifié en un test ; observer n'apprendrait rien de plus.

## Conséquences

- Brevo est un sous-traitant, ses données sont hébergées dans l'UE ([docs/rgpd.md](../rgpd.md#sous-traitants)).
- Les mails de l'offre gratuite peuvent porter le logo Brevo : accepté.
- Brevo ajoute aux mails des pixels de suivi et réécrit les liens, même avec le suivi anonyme : ce suivi est déclaré dans [docs/rgpd.md](../rgpd.md#mails-transactionnels). Il pose aussi un en-tête `List-Unsubscribe`.
- Un futur expéditeur sur `gaelv.com` partagera la réputation du domaine et sa politique DMARC.
- Impossible d'envoyer en `contact@gaelv.com` depuis une boîte personnelle : le DMARC `p=reject` le refuserait sans DKIM aligné.
- Au-delà du quota, ou si Brevo est en panne, un mail épuise ses tentatives et finit dans la file Messenger `failed` ; son rejet remonte à Sentry. Il y reste, en base, avec l'adresse du destinataire et le lien (valable 24 h pour une invitation, 1 h pour une réinitialisation), jusqu'à ce qu'on le relance ou le supprime, et au plus 7 jours après son dernier échec, où la purge nocturne le supprime ; puis 30 jours dans les sauvegardes.

## Sources

Relevées le 2026-10-30 :

- [Brevo, Data storage location](https://help.brevo.com/hc/en-us/articles/360001005510-Data-storage-location)
- [Cloudflare Email Service, Pricing](https://developers.cloudflare.com/email-service/platform/pricing/)
- [Cloudflare Email Routing, Troubleshooting](https://developers.cloudflare.com/email-routing/troubleshooting/)
