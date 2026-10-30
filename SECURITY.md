# Politique de sécurité

## Versions supportées

Seule la **dernière release** publiée reçoit des correctifs de sécurité.

| Version | Supportée |
|---|---|
| Dernière release | ✅ |
| Versions antérieures | ❌ |

## Limite d'essais du mot de passe

Un mot de passe ne peut être essayé que **5 fois par 15 minutes** (fenêtre glissante) :

- à la connexion, par couple identifiant + adresse IP (`login_throttling`) ;
- au changement de mot de passe et à la suppression du compte, avec un seul compteur par compte, partagé entre ces deux formulaires. Seul un mot de passe actuel erroné consomme un essai, un mot de passe juste remet le compteur à zéro. Au-delà, le mot de passe saisi n'est plus vérifié, la session reste ouverte, et le dépassement est journalisé.

## Limite des demandes de réinitialisation du mot de passe

« Mot de passe oublié » n'accepte que **5 demandes par 15 minutes** (fenêtre glissante) par adresse IP, que le compte existe ou non. Au-delà, le formulaire affiche une erreur, et ni le compte n'est recherché ni aucun mail n'est envoyé : le message ne révèle rien du compte. Cette limite s'ajoute à celle du bundle de réinitialisation, une demande par heure et par compte.

Derrière un proxy, l'adresse IP retenue est celle du client seulement si `TRUSTED_PROXIES` est configuré ; sinon, tous les visiteurs partagent l'adresse du proxy.

Limite connue : les compteurs ne sont pas protégés par un verrou, des envois simultanés peuvent dépasser les limites de quelques essais.

## Signaler une vulnérabilité

**N'ouvrez pas d'issue publique** pour signaler une faille de sécurité.

Utilisez le signalement privé de GitHub : onglet **Security** du dépôt → **Report a vulnerability**. Le rapport n'est visible que par le mainteneur.

Merci d'indiquer :

- la description de la vulnérabilité et son impact ;
- les étapes pour la reproduire (requête, configuration, version concernée) ;
- si possible, une piste de correction.

Ce projet est maintenu sur mon temps libre : je m'engage à accuser réception du signalement et à vous tenir informé de sa prise en charge, sans délai garanti.
