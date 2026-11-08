<?php

declare(strict_types=1);

namespace App\Display\Pairing;

use App\Entity\Device;
use App\Repository\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Appairage d'un écran par code, façon télévision : l'écran ouvre une demande
 * et affiche son code, le propriétaire d'un dispositif saisit ce code, et
 * l'écran reçoit à sa prochaine interrogation le secret de son cookie.
 *
 * Une demande est identifiée par un secret aléatoire que seul l'écran détient
 * (dans un cookie temporaire) : le code, court et affiché, ne permet pas de
 * récupérer le secret de l'écran. Le code change toutes les minutes et reste
 * accepté une minute de plus, comme le code précédent d'une appli
 * d'authentification.
 *
 * Les demandes vivent en cache, pas en base. Les durées sont vérifiées avec
 * l'horloge, l'expiration du cache ne fait que libérer la place.
 */
final readonly class ScreenPairing
{
    /** Durée d'affichage d'un code, en secondes. */
    public const int CODE_DISPLAY_SECONDS = 60;

    /** Durée pendant laquelle un code est accepté, en secondes. */
    public const int CODE_ACCEPT_SECONDS = 120;

    /** Une demande que l'écran n'interroge plus est oubliée après ce délai, en secondes. */
    private const int REQUEST_IDLE_SECONDS = 600;

    public function __construct(
        #[Target('display_pairing.cache')]
        private CacheItemPoolInterface $cache,
        #[Target('display_pairing_code')]
        private RateLimiterFactoryInterface $codeLimiter,
        #[Target('display_pairing_entry')]
        private RateLimiterFactoryInterface $entryLimiter,
        private DeviceRepository $deviceRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * État de l'appairage d'un écran qui n'a pas de cookie de dispositif.
     *
     * Reprend sa demande s'il en a une : même code tant qu'il est affiché, un
     * nouveau ensuite. Sinon, en ouvre une. Si le code a été saisi entre-temps,
     * appaire l'écran au dispositif.
     *
     * Seule la génération d'un code compte dans la limite par adresse IP.
     */
    public function forScreen(#[\SensitiveParameter] ?string $requestSecret, string $clientIp): PendingPairing|PairedScreen|PairingThrottled
    {
        $request = null === $requestSecret ? null : $this->request($requestSecret);

        if (null !== $request?->deviceId) {
            $this->cache->deleteItem($this->requestKey((string) $requestSecret));
            $device = $this->deviceRepository->find($request->deviceId);

            if ($device instanceof Device) {
                return $this->pair($device);
            }

            // Dispositif supprimé entre la saisie et l'interrogation : nouvelle demande.
            $request = null;
        }

        $secret = $request instanceof PairingRequest ? (string) $requestSecret : bin2hex(random_bytes(32));
        $now = $this->clock->now()->getTimestamp();

        if ($request instanceof PairingRequest && $now - $request->issuedAt < self::CODE_DISPLAY_SECONDS) {
            $this->saveRequest($secret, $request);

            return $this->pending($secret, $request);
        }

        if (!$this->codeLimiter->create($clientIp)->consume()->isAccepted()) {
            return new PairingThrottled();
        }

        // Code suivant : le précédent reste accepté jusqu'à l'expiration de son entrée.
        $request = new PairingRequest($this->unusedCode(), $now, $request?->code);
        $this->saveRequest($secret, $request);

        $codeItem = $this->cache->getItem($this->codeKey($request->code));
        $codeItem->set([$this->requestKey($secret), $now]);
        $codeItem->expiresAfter(self::CODE_ACCEPT_SECONDS);

        $this->cache->save($codeItem);

        return $this->pending($secret, $request);
    }

    /**
     * Saisie d'un code par le propriétaire du dispositif. Un code sert une
     * fois : le code affiché et le précédent sont alors retirés.
     *
     * Les saisies fausses sont limitées par compte.
     */
    public function confirm(string $input, Device $device): PairingConfirmation
    {
        $limiter = $this->entryLimiter->create((string) $device->getOwner()->getId());

        // consume(0) lit l'état sans consommer d'essai.
        if (0 === $limiter->consume(0)->getRemainingTokens()) {
            return PairingConfirmation::TooManyAttempts;
        }

        $code = PairingCode::normalize($input);
        $requestKey = null === $code ? null : $this->acceptedRequestKey($code);
        $requestItem = null === $requestKey ? null : $this->cache->getItem($requestKey);
        $request = $requestItem?->get();

        if (!$requestItem instanceof \Psr\Cache\CacheItemInterface || !$request instanceof PairingRequest || null !== $request->deviceId) {
            $limiter->consume();

            return PairingConfirmation::UnknownCode;
        }

        $limiter->reset();

        $requestItem->set($request->confirmedFor((int) $device->getId()));
        $requestItem->expiresAfter(self::REQUEST_IDLE_SECONDS);

        $this->cache->save($requestItem);
        $this->cache->deleteItems(array_map($this->codeKey(...), array_filter([$request->code, $request->previousCode])));

        return PairingConfirmation::Confirmed;
    }

    /**
     * Clé de la demande qui a affiché ce code, s'il est encore accepté.
     */
    private function acceptedRequestKey(string $code): ?string
    {
        $entry = $this->cache->getItem($this->codeKey($code))->get();

        if (!\is_array($entry) || !\is_string($entry[0] ?? null) || !\is_int($entry[1] ?? null)) {
            return null;
        }

        return $this->clock->now()->getTimestamp() - $entry[1] <= self::CODE_ACCEPT_SECONDS ? $entry[0] : null;
    }

    private function pair(Device $device): PairedScreen
    {
        $screenSecret = bin2hex(random_bytes(32));
        $device->pairScreen($screenSecret);
        $this->entityManager->flush();

        return new PairedScreen($device, $screenSecret);
    }

    private function pending(string $requestSecret, PairingRequest $request): PendingPairing
    {
        return new PendingPairing(
            $requestSecret,
            $request->code,
            $this->clock->now()->setTimestamp($request->issuedAt + self::CODE_DISPLAY_SECONDS),
        );
    }

    private function request(#[\SensitiveParameter] string $requestSecret): ?PairingRequest
    {
        $request = $this->cache->getItem($this->requestKey($requestSecret))->get();

        return $request instanceof PairingRequest ? $request : null;
    }

    private function saveRequest(#[\SensitiveParameter] string $requestSecret, PairingRequest $request): void
    {
        $item = $this->cache->getItem($this->requestKey($requestSecret));
        $item->set($request);
        $item->expiresAfter(self::REQUEST_IDLE_SECONDS);

        $this->cache->save($item);
    }

    /**
     * Un code qu'aucune demande en cours n'utilise.
     */
    private function unusedCode(): string
    {
        do {
            $code = PairingCode::generate();
        } while ($this->cache->hasItem($this->codeKey($code)));

        return $code;
    }

    /**
     * La clé porte le haché du secret, pas le secret : le cache sur disque ne
     * le contient jamais en clair.
     */
    private function requestKey(#[\SensitiveParameter] string $requestSecret): string
    {
        return 'request_'.hash('sha256', $requestSecret);
    }

    private function codeKey(string $code): string
    {
        return 'code_'.$code;
    }
}
