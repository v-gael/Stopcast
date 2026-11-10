# Envoi des mails par AhaSend, sans suivi

Remplace l'[ADR 0007](0007-envoi-des-mails.md). Brevo ajoutait un pixel et réécrivait les liens des mails transactionnels, même avec le suivi anonyme, sans réglage pour le couper sur l'offre gratuite. La [recommandation de la CNIL](https://www.cnil.fr/fr/recommandation-pixel-suivi-courriels) soumet ces pixels au consentement, que Stopcast ne recueille pas et dont il n'a pas l'usage. Le budget n'admet toujours aucun service payant ([ADR 0006](0006-hebergement.md)).

Nous envoyons par **AhaSend**, sur l'offre gratuite **sans carte bancaire** : 1 000 mails par mois. Sa documentation indique que le suivi des ouvertures et des clics est désactivé par défaut.

- **Domaine** : inchangé, `Stopcast <no-reply@gaelv.com>`, réponses vers `contact@gaelv.com`.
- **Transport** : le DSN SMTP natif de Symfony (`smtp://…@send.ahasend.com:587`), avec un identifiant limité au domaine `gaelv.com`. Aucun paquet en plus.
- **Authentification** : DKIM d'AhaSend aligné sur `gaelv.com`, adresse de retour sur `psrp.gaelv.com`, et `include:spf.ahasend.com` ajouté au SPF de la racine, partagé avec Email Routing. Le DMARC reste en `p=reject`.
- **Suivi** : le CNAME de suivi proposé par AhaSend (`t.gaelv.com`) n'est pas publié.

Les enregistrements DNS sont décrits dans [docs/exploitation.md](../exploitation.md#mails).

## Options écartées

- **Rester chez Brevo** : le suivi ne se coupe pas seul, et la désactivation au cas par cas dépend de son support.
- **Sweego** : 100 mails par jour, hébergé en France, mais l'absence de suivi par défaut n'est pas écrite dans sa documentation.
- **Mailjet** : 6 000 mails par mois, mais le retrait de son logo est listé dans l'offre payante.
- **Lettermint** : 300 mails par mois seulement.
- **Resend** : traitement aux États-Unis, même en région UE.

## Conséquences

- AhaSend B.V. est un sous-traitant, ses données restent dans l'EEE ([docs/rgpd.md](../rgpd.md#sous-traitants)).
- Un envoi réel du 10 novembre 2026 confirme l'absence de pixel, de logo, de lien réécrit et de `List-Unsubscribe`, avec SPF, DKIM aligné et DMARC valides. Le suivi ne doit pas être réactivé : la politique de confidentialité s'y engage.
- AhaSend reste visible dans les en-têtes techniques : `X-AhaSendRef`, seconde signature DKIM à son nom, nom du serveur d'envoi.
- Tant qu'AhaSend n'a pas vérifié le compte, le contenu des mails, liens à jeton compris, peut passer par une analyse automatique dans l'EEE, déclarée dans le registre.
- Au-delà du quota mensuel, l'envoi s'arrête sans facturation : un mail épuise ses tentatives et finit dans la file Messenger `failed`, comme lors d'une panne d'AhaSend.
- Le domaine récent a reçu un score de spam de 5 chez Outlook lors du premier essai : la réputation de `gaelv.com` reste à construire.

## Sources

Relevées le 2026-11-10 :

- [AhaSend, tarifs](https://ahasend.com/pricing)
- [AhaSend, suivi des ouvertures](https://ahasend.com/docs/tracking/open-tracking) et [des clics](https://ahasend.com/docs/tracking/click-tracking)
- [AhaSend, DPA](https://ahasend.com/dpa)
