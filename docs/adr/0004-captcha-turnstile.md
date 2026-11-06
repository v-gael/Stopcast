# Captcha Cloudflare Turnstile sur la connexion et « mot de passe oublié »

La connexion et « mot de passe oublié » n'étaient protégées que par des limites de débit, par identifiant et adresse IP ou par adresse IP. Un robot qui change d'adresse peut tester des identifiants, ou déclencher des mails de réinitialisation à la chaîne.

Nous ajoutons un **captcha Cloudflare Turnstile**, toujours affiché, sur ces deux formulaires seulement. L'acceptation d'une invitation n'en a pas : le jeton du lien la protège déjà. Le widget est en rendu explicite, piloté par un contrôleur Stimulus pour suivre les visites Turbo. Le script de Cloudflare n'est chargé que sur ces deux pages. Le serveur vérifie le jeton auprès de siteverify avec un service maison (`TurnstileVerifier`) :

- à la connexion, après la limite de débit et avant le chargement du compte : au-delà de la limite, Cloudflare n'est pas appelé, et un captcha raté compte comme un essai ;
- sur « mot de passe oublié », avec la validation du formulaire, avant la limite de débit : un captcha raté ne la consomme pas, et aucun mail ne part.

Cloudflare est déjà notre fournisseur de domaine, de proxy et de tunnel ([ADR 0006](0006-hebergement.md)), et Turnstile s'utilise sans que le site passe par son proxy.

## Comportement en panne

- Jeton absent, vide ou de plus de 2048 caractères : refus, sans appel à Cloudflare.
- Cloudflare répond `success: false` : refus, y compris quand il signale une erreur de configuration ou interne (`missing-input-secret`, `invalid-input-secret`, `internal-error`, `bad-request`). Ces codes sont journalisés en `error`, qui remonte dans Sentry : une clé secrète fausse en prod bloque toutes les connexions et doit se voir vite.
- Cloudflare injoignable, plus de 5 s sans réponse, erreur HTTP ou réponse illisible : le jeton passe, avec un `warning`. Les limites de débit restent la seule protection le temps de la panne, plutôt qu'un site où plus personne ne se connecte.

## Options écartées

- **Google reCAPTCHA** : il pose des cookies Google et sert aussi au suivi publicitaire, ce qui demande un bandeau de consentement.
- **hCaptcha** et **Friendly Captcha** : chacun ajoute un sous-traitant, alors que Cloudflare l'est déjà pour le domaine, le proxy et le tunnel.
- **Bundle `pixelopen/cloudflare-turnstile-bundle`** : il ne fournit qu'un champ du composant Form, alors que la connexion est un formulaire HTML traité par `form_login`, et il refuse l'envoi quand Cloudflare ne répond pas, sans option pour laisser passer.

## Conséquences

- Pas de bandeau de consentement : Turnstile ne pose pas de cookie de suivi, et la documentation n'en cite qu'un, en mode pre-clearance, que nous n'utilisons pas.
- Cloudflare reçoit, pour ces deux formulaires, l'adresse IP, le user-agent et l'empreinte TLS du visiteur. Il est sous-traitant pour la détection des robots, mais aussi responsable de traitement, au titre de son intérêt légitime, quand il utilise ces signaux pour améliorer ses modèles ([politique de Turnstile](https://www.cloudflare.com/turnstile-privacy-policy/)). La politique de confidentialité le liste parmi les sous-traitants.
- La CSP autorise `https://challenges.cloudflare.com` en `script-src` et `frame-src`.
- `TURNSTILE_SITE_KEY` et `TURNSTILE_SECRET_KEY` valent en dev les clés de test « toujours valide » de Cloudflare. En test, le client HTTP est simulé et aucun appel ne part. En prod, `bin/smoke-test` échoue si la page d'accueil sert la clé de site de test.
- Les formulaires ne fonctionnent plus sans JavaScript : sans le widget, aucun jeton n'est envoyé.
