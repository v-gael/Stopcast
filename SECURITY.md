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

## En-têtes de sécurité HTTP

L'application pose elle-même ses en-têtes de sécurité (`nelmio/security-bundle`, configuré dans `config/packages/nelmio_security.yaml`), sur toutes les réponses, API comprise :

- `X-Content-Type-Options: nosniff` ;
- `X-Frame-Options: DENY` ;
- `Referrer-Policy: strict-origin-when-cross-origin` ;
- `Permissions-Policy`, qui coupe la caméra, le micro, la géolocalisation, le paiement et l'USB ;
- `Cross-Origin-Opener-Policy: same-origin`.

La **Content Security Policy** est stricte et bloquante dans tous les environnements : seules les ressources de la même origine sont chargées, sans `unsafe-inline` ni `unsafe-eval`, et les scripts et styles inline ne passent qu'avec le nonce tiré à chaque réponse (`csp_nonce()` dans Twig). La page ne peut pas être affichée dans un cadre (`frame-ancestors 'none'`) et les formulaires ne s'envoient qu'à la même origine. En développement, `https://cdn.jsdelivr.net` est autorisé en plus pour les scripts du hot reload FrankenPHP. Les violations ne sont pas encore remontées au serveur.

HSTS n'est pas posé par l'application : il revient au serveur qui termine le TLS.

## Proxy et hôtes de confiance

L'application ne croit les en-têtes `X-Forwarded-*` que venant des proxys listés dans `TRUSTED_PROXIES`, et refuse (400) tout hôte absent de `TRUSTED_HOSTS`, à partir duquel sont construits les liens des emails (voir [docs/developpement.md](docs/developpement.md#proxy-et-hôtes-de-confiance)).

## Signaler une vulnérabilité

**N'ouvrez pas d'issue publique** pour signaler une faille de sécurité.

Utilisez le signalement privé de GitHub : onglet **Security** du dépôt → **Report a vulnerability**. Le rapport n'est visible que par le mainteneur.

Merci d'indiquer :

- la description de la vulnérabilité et son impact ;
- les étapes pour la reproduire (requête, configuration, version concernée) ;
- si possible, une piste de correction.

Ce projet est maintenu sur mon temps libre : je m'engage à accuser réception du signalement et à vous tenir informé de sa prise en charge, sans délai garanti.
