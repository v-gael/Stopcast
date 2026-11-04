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

Limite connue : les compteurs ne sont pas protégés par un verrou, des envois simultanés peuvent dépasser les limites de quelques essais (de même pour l'appairage des écrans, plus bas).

## Appairage des écrans

Un écran d'affichage s'authentifie par un cookie reçu à l'appairage (voir [ADR 0008](docs/adr/0008-affichage-html-et-appairage.md)) :

- Le cookie contient un secret aléatoire de 256 bits ; seul son haché SHA-256 est enregistré. Il est `HttpOnly`, `Secure`, `SameSite=Strict`, préfixé `__Host-` en production, valable 400 jours et renvoyé à chaque réponse. Il n'ouvre que la page d'affichage, jamais l'espace web, et une session d'utilisateur n'ouvre pas la page d'affichage.
- Le code d'appairage (six caractères parmi 31) est affiché une minute et accepté deux, une seule fois. L'écran interroge le serveur avec le secret de sa demande, gardé dans un cookie temporaire : connaître le code ne permet pas de récupérer le cookie de l'écran.
- La génération de codes est limitée à **150 par heure** et par adresse IP ; la saisie à **5 codes faux par 15 minutes** et par compte (fenêtres glissantes). Au-delà, même un bon code est refusé.
- Dissocier un écran, ou en appairer un autre au même dispositif, coupe l'accès de l'ancien écran.

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

## Sessions

- La session dure le temps du navigateur et expire après **2 heures d'inactivité** (au plus tôt : le ramasse-miettes des sessions est probabiliste, une session inactive peut survivre un peu plus longtemps quand le trafic est faible).
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

Le site déployé publie ce point de contact dans [`/.well-known/security.txt`](public/.well-known/security.txt) ([RFC 9116](https://www.rfc-editor.org/rfc/rfc9116)). Sa date `Expires` est renouvelée chaque année ; un test échoue 30 jours avant l'échéance pour le rappeler.

Ce projet est maintenu sur mon temps libre : je m'engage à accuser réception du signalement et à vous tenir informé de sa prise en charge, sans délai garanti.
