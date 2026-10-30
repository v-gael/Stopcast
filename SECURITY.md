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

Limite connue : le compteur n'est pas protégé par un verrou, des envois simultanés peuvent dépasser la limite de quelques essais.

## Sessions

- La session dure le temps du navigateur et expire après **2 heures d'inactivité**.
- « Se souvenir de moi », sur case à cocher, garde la connexion **30 jours**.
- Les deux cookies sont `HttpOnly` et `SameSite=Lax` ; en production, ils sont aussi `Secure` et préfixés `__Host-`, ce qui interdit à un autre site du domaine parent de les remplacer.
- Changer son mot de passe déconnecte les autres sessions ouvertes sur le compte et invalide les cookies « se souvenir de moi » émis avant le changement.
- Les sessions sont stockées sur le serveur d'application : un redéploiement les ferme, « se souvenir de moi » reconnecte.

## Signaler une vulnérabilité

**N'ouvrez pas d'issue publique** pour signaler une faille de sécurité.

Utilisez le signalement privé de GitHub : onglet **Security** du dépôt → **Report a vulnerability**. Le rapport n'est visible que par le mainteneur.

Merci d'indiquer :

- la description de la vulnérabilité et son impact ;
- les étapes pour la reproduire (requête, configuration, version concernée) ;
- si possible, une piste de correction.

Ce projet est maintenu sur mon temps libre : je m'engage à accuser réception du signalement et à vous tenir informé de sa prise en charge, sans délai garanti.
