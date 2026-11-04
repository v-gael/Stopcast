<?php

declare(strict_types=1);

namespace App\Display\Pairing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookies de l'écran d'affichage : celui du dispositif (le secret de l'écran
 * appairé, 400 jours) et celui de la demande d'appairage en cours (le temps de
 * la session du navigateur).
 *
 * HttpOnly, Secure, SameSite=Strict, Path=/ ; préfixés « __Host- » en prod,
 * comme le cookie de remember-me.
 */
final readonly class DisplayCookies
{
    /** Durée de vie du cookie du dispositif, renouvelée à chaque réponse authentifiée : le maximum accepté par Chrome. */
    public const int SCREEN_LIFETIME_DAYS = 400;

    private string $screenName;

    private string $pairingName;

    public function __construct(
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
        $response->headers->clearCookie($this->screenName, '/', null, true, true, Cookie::SAMESITE_STRICT);
    }

    public function setPairing(Response $response, #[\SensitiveParameter] string $requestSecret): void
    {
        $response->headers->setCookie($this->cookie($this->pairingName, $requestSecret, 0));
    }

    public function clearPairing(Response $response): void
    {
        $response->headers->clearCookie($this->pairingName, '/', null, true, true, Cookie::SAMESITE_STRICT);
    }

    private function value(Request $request, string $name): ?string
    {
        $value = $request->cookies->get($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function cookie(string $name, #[\SensitiveParameter] string $value, \DateTimeInterface|int $expire): Cookie
    {
        return Cookie::create($name, $value, $expire, '/', null, true, true, false, Cookie::SAMESITE_STRICT);
    }
}
