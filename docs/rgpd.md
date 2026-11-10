# Registre des traitements (RGPD)

Registre minimal des traitements de données personnelles de Stopcast. Il est la source de la [politique de confidentialité](../templates/legal/privacy.html.twig) publiée sur `/confidentialite` : toute modification ici se reporte sur la page, et inversement.

- **Responsable du traitement** : `v-gael`, particulier, joignable à `contact@gaelv.com`.
- **Personnes concernées** : les utilisateurs (comptes ouverts sur invitation d'un administrateur, administrateurs compris) et les visiteurs des pages publiques.
- **Consentement** : aucun traitement ne repose sur le consentement ; aucun cookie ne demande d'accord, il n'y a donc pas de bandeau.
- **Droits** : accès et portabilité par l'export JSON de « Mon compte », effacement par la suppression du compte (« Mon compte »), le reste (rectification, opposition, limitation, accès aux logs) par email. Pour le suivi ajouté aux mails par Brevo, l'opposition passe par le moyen décrit dans « Mails transactionnels ». Réclamation possible auprès de la [CNIL](https://www.cnil.fr/fr/plaintes).

Dernière mise à jour : 10 novembre 2026.

## Traitements

### Gestion des comptes et des invitations

| | |
|---|---|
| Finalité | Ouvrir un compte sur invitation, s'authentifier, gérer son compte, administrer les comptes. |
| Données | Email, rôle, mot de passe haché, date de création ; demandes d'invitation et de réinitialisation (jeton haché, dates) ; cookies de session et « se souvenir de moi ». |
| Personnes concernées | Utilisateurs. |
| Base légale | Exécution du contrat ([conditions d'utilisation](../templates/legal/terms.html.twig)). |
| Durée | Compte : jusqu'à sa suppression par l'utilisateur ou un administrateur, ou après 2 ans sans connexion (voir « Purge des comptes inactifs »). Demandes d'invitation (lien valable 24 heures) et de réinitialisation (1 heure) : supprimées à leur expiration. Compte en attente (invitation jamais acceptée) : supprimé 30 jours après l'expiration de son dernier lien. Session : fin du navigateur, ou 2 heures d'inactivité ; « se souvenir de moi » : 30 jours. |
| Destinataires et sous-traitants | Administrateurs du service ; OVH (hébergement), Cloudflare (proxy, sauvegardes). |

### Dispositifs et configurations d'affichage

| | |
|---|---|
| Finalité | Afficher les prochains passages sur les écrans de l'utilisateur ; montrer le dernier contact de chaque écran et signaler un écran hors ligne. |
| Données | Nom de chaque dispositif et haché du secret de son écran appairé (gardé par l'écran dans un cookie), état actif, durée d'affichage et intervalle de rafraîchissement, date du dernier contact de l'écran (à 5 minutes près), dates de création et de modification ; configurations d'affichage (arrêts choisis, ordre de rotation). |
| Personnes concernées | Utilisateurs. |
| Base légale | Exécution du contrat. |
| Durée | Jusqu'à la suppression du dispositif ou du compte. |
| Destinataires et sous-traitants | OVH, Cloudflare. |

### Mails transactionnels

| | |
|---|---|
| Finalité | Envoyer les invitations, les liens de réinitialisation du mot de passe et les avis liés au compte. |
| Données | Email du destinataire, contenu du mail (lien à usage unique). Suivi ajouté par Brevo : ouvertures et clics, adresse IP et navigateur reçus lors d'une ouverture ou d'un clic. Stopcast n'utilise pas ces mesures. Pour l'éviter : bloquer l'affichage des images dans sa messagerie et utiliser l'adresse écrite sous le bouton, qui mène directement à Stopcast. |
| Personnes concernées | Utilisateurs et personnes invitées. |
| Base légale | Exécution du contrat ; pour le suivi ajouté par Brevo, intérêt légitime d'assurer l'envoi des mails. |
| Durée | Côté Stopcast, rien n'est conservé hors de la demande elle-même (voir « Gestion des comptes »), sauf un mail en échec : après ses tentatives, il reste dans la file `failed`, en base, avec l'adresse du destinataire et son contenu, jusqu'à ce qu'on le relance ou le supprime, et au plus 7 jours après son dernier échec, où la purge nocturne le supprime ([exploitation.md](exploitation.md#mails-en-échec)) ; puis 30 jours dans les sauvegardes. Côté Brevo : non précisé. |
| Destinataires et sous-traitants | Brevo (envoi et suivi des ouvertures et des clics, avec l'option de suivi anonyme activée). |

### Export des données du compte

| | |
|---|---|
| Finalité | Permettre à l'utilisateur d'exercer ses droits d'accès et de portabilité. |
| Données | Compte, dispositifs (sans le haché du secret de leur écran) et configurations d'affichage, générés à la demande. |
| Personnes concernées | Utilisateurs. |
| Base légale | Exécution du contrat. |
| Durée | Aucune : le fichier est produit à la demande et n'est pas conservé. |
| Destinataires et sous-traitants | L'utilisateur lui-même ; OVH, Cloudflare. |

### Sécurité

| | |
|---|---|
| Finalité | Protéger le service et les comptes : journaux du serveur, limites de débit (connexion, mot de passe oublié, mot de passe actuel), captcha, refus des mots de passe ayant fuité. |
| Données | Journaux : adresse IP tronquée, email masqué, requête (méthode, URL sans jeton de réinitialisation ni d'invitation, statut) et erreur ; aucun en-tête HTTP. Limites de débit : identifiant saisi ou compte, adresse IP, compteurs. Captcha : adresse IP, empreinte TLS, user-agent, transmis à Cloudflare Turnstile. Mots de passe ayant fuité : seuls les 5 premiers caractères de l'empreinte SHA-1 du mot de passe sont envoyés à Have I Been Pwned. |
| Personnes concernées | Utilisateurs et visiteurs. |
| Base légale | Intérêt légitime (sécurité du service). Pas de consentement pour le captcha : la CNIL en dispense les traceurs destinés à la sécurité d'un mécanisme d'authentification, et Turnstile ne protège que la connexion et « mot de passe oublié », sans son cookie de pre-clearance ; Cloudflare qualifie ses signaux de strictement nécessaires à la détection des robots. |
| Durée | Journaux : jusqu'au déploiement suivant, qui recrée les conteneurs, et au plus 50 Mo par service (rotation par taille). Compteurs des limites : 15 minutes après le dernier essai. Captcha et contrôle des mots de passe : rien n'est conservé par Stopcast. |
| Destinataires et sous-traitants | OVH, Cloudflare (proxy, Turnstile). Cloudflare est aussi responsable de traitement distinct des signaux Turnstile, au titre de son intérêt légitime, pour améliorer sa détection des robots ([politique Turnstile](https://www.cloudflare.com/turnstile-privacy-policy/)). Have I Been Pwned est un service tiers, pas un sous-traitant : il ne reçoit aucune donnée personnelle. |

### Suivi des erreurs

| | |
|---|---|
| Finalité | Être prévenu des erreurs de l'application en production et les corriger. |
| Données | Message d'erreur, trace d'exécution (sans arguments ni variables), méthode et URL de la requête, contexte technique ; IP et emails masqués avant l'envoi, ni utilisateur, ni cookies, ni en-têtes, ni corps de requête (`data_collection`). |
| Personnes concernées | Utilisateurs et visiteurs, de façon résiduelle. |
| Base légale | Intérêt légitime (fiabilité du service). |
| Durée | 30 jours (conservation de l'offre Developer de Sentry). |
| Destinataires et sous-traitants | Sentry. |

### Supervision

| | |
|---|---|
| Finalité | Vérifier que le service répond et que les tâches planifiées (import, sauvegarde) tournent. |
| Données | Aucune donnée personnelle : une sonde interroge `/health`, les tâches envoient un signal de bon fonctionnement. |
| Personnes concernées | Aucune. |
| Base légale | Sans objet (intérêt légitime pour le fonctionnement du service). |
| Durée | Sans objet. |
| Destinataires et sous-traitants | UptimeRobot, Healthchecks.io. |

### Sauvegardes et journal des suppressions

| | |
|---|---|
| Finalité | Restaurer le service après un incident, sans faire revivre les comptes supprimés entre-temps. |
| Données | Sauvegarde chiffrée de toute la base ; journal des comptes supprimés (identifiant et date de création, sans email), rejoué après une restauration. |
| Personnes concernées | Utilisateurs. |
| Base légale | Intérêt légitime (continuité du service, effectivité de l'effacement). |
| Durée | 30 jours pour les sauvegardes comme pour le journal. |
| Destinataires et sous-traitants | OVH, Cloudflare (stockage R2). |

### Purge des comptes inactifs

| | |
|---|---|
| Finalité | Ne pas conserver les comptes abandonnés. |
| Données | Email, date de dernière connexion (l'activité d'un écran ne compte pas), date de l'avertissement. |
| Personnes concernées | Utilisateurs. |
| Base légale | Intérêt légitime (limitation de la conservation). |
| Durée | Avertissement par email 30 jours avant la suppression ; compte supprimé après 2 ans sans connexion. |
| Destinataires et sous-traitants | Brevo (avertissement, avec le suivi décrit dans « Mails transactionnels »), OVH, Cloudflare. |

## Sous-traitants

Relevé le 31 octobre 2026 ; hébergeur relevé le 1er novembre 2026. Une information absente des documents publics est notée « non précisé ».

| Service | Rôle | Données reçues | Pays de stockage | Transfert hors UE | Source |
|---|---|---|---|---|---|
| OVH SAS | Hébergement du serveur (VPS) et de la base | Toutes les données de l'application, journaux | France (Strasbourg) | Aucun : le serveur est dans le datacenter choisi, dans l'UE | [Sécurité juridique et confidentialité](https://www.ovhcloud.com/fr/personal-data-protection/legal-privacy-security/) |
| Cloudflare, Inc. | DNS, proxy, Tunnel, Turnstile, stockage des sauvegardes (R2), Email Routing (`contact@gaelv.com`). Pour Turnstile, aussi responsable de traitement distinct : il utilise les signaux pour améliorer sa détection des robots | Trafic du site (IP, requêtes), signaux Turnstile (IP, empreinte TLS, user-agent), sauvegardes chiffrées, mails envoyés à l'adresse de contact | États-Unis et UE (métadonnées et journaux) ; sauvegardes R2 : UE si le bucket est créé avec la juridiction UE | EU-U.S. Data Privacy Framework, clauses contractuelles types à défaut | [DPA](https://www.cloudflare.com/cloudflare-customer-dpa/), [GDPR](https://www.cloudflare.com/trust-hub/gdpr/), [Turnstile](https://www.cloudflare.com/turnstile-privacy-policy/), [R2](https://developers.cloudflare.com/r2/reference/data-location/) |
| Brevo | Envoi des mails transactionnels, suivi des ouvertures et des clics (pixel, liens réécrits ; option de suivi anonyme activée, relevé le 9 novembre 2026) | Email du destinataire, contenu du mail, adresse IP et navigateur lors d'une ouverture ou d'un clic | UE : France et Allemagne (OVH), Belgique (Google Cloud) | Non précisé | [Data storage location](https://help.brevo.com/hc/en-us/articles/360001005510-Data-storage-location) |
| Sentry | Suivi des erreurs | Erreurs sans IP ni email | Allemagne (Francfort) pour les événements ; États-Unis pour les données du compte Sentry | EU-U.S. Data Privacy Framework, clauses contractuelles types à défaut | [Data storage location](https://docs.sentry.io/organization/data-storage-location/), [DPA](https://sentry.io/legal/dpa/) |
| UptimeRobot s. r. o. | Sonde de disponibilité | Aucune donnée personnelle | UE en principal, États-Unis en secondaire | Clauses contractuelles types | [DPA](https://uptimerobot.com/dpa/) |
| SIA Monkey See Monkey Do (Healthchecks.io) | Surveillance des tâches planifiées | Aucune donnée personnelle | Non précisé (hébergé chez Hetzner, sauvegardes chez AWS) | Non précisé | [Privacy Policy](https://healthchecks.io/privacy/) |

## Service tiers

[Have I Been Pwned](https://haveibeenpwned.com/API/v3#PwnedPasswords) (Pwned Passwords) est interrogé par k-anonymat lors du choix d'un mot de passe : seuls les 5 premiers caractères de l'empreinte SHA-1 du mot de passe sont envoyés, la comparaison se fait sur le serveur de Stopcast. Aucune donnée personnelle n'est transmise : ce n'est pas un sous-traitant.

## Non décidé

La mesure d'audience n'est pas décidée ; ce registre et la page seront mis à jour le jour où elle l'est.
