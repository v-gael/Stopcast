<?php

declare(strict_types=1);

namespace App\Display\Pairing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookies de l'écran d'affichage : celui du dispositif (le secret de l'écran
 * appairé, 400 jours) et celui de la demande d'appairage en cours (le temps de
 * la session du navigateur).
 *
 * HttpOnly, SameSite=Strict, Path=/ ; préfixés « __Host- » en prod, comme le
 * cookie de remember-me. Secure dès que la requête arrive en HTTPS, comme le
 * réglage « cookie_secure: auto » des sessions : le navigateur de l'écran
 * (cog, WPE WebKit) refuse un cookie Secure reçu en HTTP, même sur localhost,
 * ce qui bloquerait l'appairage contre l'instance de dev. En prod, le site est
 * en HTTPS et le préfixe « __Host- » impose de toute façon Secure.
 */
final readonly class DisplayCookies
{
    /** Durée de vie du cookie du dispositif, renouvelée à chaque réponse authentifiée : le maximum accepté par Chrome. */
    public const int SCREEN_LIFETIME_DAYS = 400;

    private string $screenName;

    private string $pairingName;

    public function __construct(
        private RequestStack $requestStack,
        #[Autowire(param: 'app.display_cookie_prefix')]
        string $prefix,
    ) {
        $this->screenName = $prefix.'STOPCAST_DISPLAY';
        $this->pairingName = $prefix.'STOPCAST_DISPLAY_PAIRING';
    }

    public function screenSecret(Request $request): ?string
    {
        return $this->value($request, $this->screenName);
    }

    public function pairingSecret(Request $request): ?string
    {
        return $this->value($request, $this->pairingName);
    }

    public function setScreen(Response $response, #[\SensitiveParameter] string $screenSecret, \DateTimeImmutable $now): void
    {
        $response->headers->setCookie($this->cookie($this->screenName, $screenSecret, $now->modify(\sprintf('+%d days', self::SCREEN_LIFETIME_DAYS))));
    }

    public function hasScreenCookie(Response $response): bool
    {
        return array_any($response->headers->getCookies(), fn ($cookie) => $cookie->getName() === $this->screenName);
    }

    public function clearScreen(Response $response): void
    {
        $this->clear($response, $this->screenName);
    }

    public function setPairing(Response $response, #[\SensitiveParameter] string $requestSecret): void
    {
        $response->headers->setCookie($this->cookie($this->pairingName, $requestSecret, 0));
    }

    public function clearPairing(Response $response): void
    {
        $this->clear($response, $this->pairingName);
    }

    private function value(Request $request, string $name): ?string
    {
        $value = $request->cookies->get($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function clear(Response $response, string $name): void
    {
        $response->headers->setCookie($this->cookie($name, '', 1));
    }

    private function cookie(string $name, #[\SensitiveParameter] string $value, \DateTimeInterface|int $expire): Cookie
    {
        $secure = $this->requestStack->getMainRequest()?->isSecure() ?? true;

        return Cookie::create($name, $value, $expire, '/', null, $secure, true, false, Cookie::SAMESITE_STRICT);
    }
}
