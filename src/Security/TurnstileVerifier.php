<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Vérifie un jeton Cloudflare Turnstile auprès de siteverify. Un jeton refusé
 * par Cloudflare est invalide, même quand Cloudflare signale une erreur de
 * configuration ou interne (journalisée en error). Cloudflare injoignable,
 * en erreur HTTP ou illisible : le jeton passe, avec un warning, et les
 * limites de débit restent la seule protection.
 */
final readonly class TurnstileVerifier
{
    /**
     * Champ que le widget ajoute au formulaire qui l'entoure.
     */
    public const string RESPONSE_FIELD = 'cf-turnstile-response';

    /**
     * Message unique d'un refus, quelle qu'en soit la cause.
     */
    public const string FAILURE_MESSAGE = 'La vérification anti-robot a échoué. Réessayez.';

    /**
     * Erreurs de configuration ou de Cloudflare, pas du jeton.
     */
    private const array REPORTED_ERROR_CODES = ['missing-input-secret', 'invalid-input-secret', 'internal-error', 'bad-request'];

    /**
     * Longueur maximale d'un jeton d'après la documentation de Turnstile.
     */
    private const int MAX_TOKEN_LENGTH = 2048;

    public function __construct(
        #[Target('turnstile.client')]
        private HttpClientInterface $turnstileClient,
        private LoggerInterface $logger,
        #[Autowire(env: 'TURNSTILE_SECRET_KEY')]
        private string $secret,
    ) {
    }

    /**
     * @param string|null $token le champ RESPONSE_FIELD du formulaire
     */
    public function verify(?string $token): bool
    {
        // Sans JavaScript, le champ manque : pas besoin de Cloudflare pour refuser.
        if (null === $token || '' === $token || \strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        try {
            $result = $this->turnstileClient->request('POST', '/turnstile/v0/siteverify', [
                'body' => ['secret' => $this->secret, 'response' => $token],
            ])->toArray();
        } catch (HttpClientExceptionInterface $httpClientException) {
            return $this->outage($httpClientException::class.': '.$httpClientException->getMessage());
        }

        if (!\is_bool($result['success'] ?? null)) {
            return $this->outage('no success field in the response');
        }

        if ($result['success']) {
            return true;
        }

        $errorCodes = \is_array($result['error-codes'] ?? null) ? array_values(array_filter($result['error-codes'], \is_string(...))) : [];
        if ([] !== array_intersect($errorCodes, self::REPORTED_ERROR_CODES)) {
            $this->logger->error('Turnstile siteverify reported an error: {error_codes}.', ['error_codes' => $errorCodes]);
        }

        return false;
    }

    private function outage(string $cause): bool
    {
        $this->logger->warning('Turnstile siteverify unavailable, token let through: {cause}.', ['cause' => $cause]);

        return true;
    }
}
