# Page d'affichage HTML et appairage par code à la place de l'API JSON

Le Raspberry Pi lisait `GET /api/next-passages` avec son jeton (`X-Device-Token`) et dessinait lui-même l'écran (client Python, Pillow). La mise en page vivait donc hors de Stopcast : le site ne pouvait pas la montrer, et toute personnalisation (couleurs, thèmes) aurait demandé un second rendu à garder identique au premier.

Stopcast fait désormais la mise en page : la **page d'affichage** (`/display`), en HTML, que le site montre en aperçu et que l'écran ouvre dans un navigateur en kiosque. Un navigateur en kiosque ne sait pas envoyer d'en-tête : l'écran s'authentifie par un **cookie de dispositif**, obtenu par **appairage par code**, façon télévision (RFC 8628, « device authorization grant »).

- L'écran sans cookie affiche un code de six caractères et un compte à rebours. Le code change toutes les minutes et reste accepté une minute de plus.
- Le propriétaire saisit le code sur la fiche de son dispositif.
- L'écran, qui interroge le serveur toutes les 5 s, reçoit alors un secret aléatoire de 256 bits dans un cookie `HttpOnly`, `Secure`, `SameSite=Strict`, de 400 jours, renvoyé à chaque réponse. Seul son haché SHA-256 est enregistré sur le dispositif.

Aucun secret n'est configuré sur le Pi : seule l'adresse de l'instance l'est.

## Options écartées

- **Génération d'une image côté serveur** pour le Pi : un second moteur de rendu à garder identique à la page de l'aperçu.
- **Garder l'API JSON** à côté de la page : une surface et un jeton en clair sans consommateur une fois le Pi passé à la page.
- **Jeton dans l'URL** de la page : il fuit dans les logs (Caddy, Symfony, Cloudflare) et l'en-tête `Referer`. Même défaut pour des identifiants dans l'URL, et pour une URL d'appairage à usage unique que le Pi rouvrirait à chaque démarrage.
- **Extension de navigateur qui ajoute l'en-tête**, **mTLS** : de la configuration et des secrets à poser sur chaque Pi.
- **Interroger le serveur avec le code** : le code est court et affiché ; qui l'interrogerait en boucle sur des codes au hasard pourrait récupérer le cookie d'un écran au moment de sa validation. L'écran interroge avec le secret de sa demande, gardé dans un cookie temporaire : le code n'est qu'un libellé pour la saisie.

## Conséquences

- Un firewall `display`, stateless et à part de celui des comptes, authentifie l'écran par son cookie (`access_token` avec un extracteur de cookie) : une session d'utilisateur n'ouvre pas la page d'affichage, le cookie n'ouvre pas l'espace web.
- Un dispositif n'a qu'un écran : appairer de nouveau remplace le haché, « Dissocier » l'efface, et l'ancien écran revient à l'écran d'appairage. Un écran passe d'un compte à un autre en revenant à l'appairage.
- Les demandes d'appairage vivent en cache, pas en base. La génération de codes est limitée par adresse IP, la saisie par compte.
- L'API JSON, API Platform et `X-Device-Token` disparaissent ensuite, avant le premier déploiement.
