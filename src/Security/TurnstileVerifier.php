<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Vérifie un jeton Cloudflare Turnstile auprès de siteverify, avec l'IP du
 * visiteur. Un jeton refusé par Cloudflare est invalide, même quand Cloudflare
 * signale une erreur de configuration ou interne (journalisée en error). Un
 * jeton validé doit venir de l'hôte de la requête et de l'action du
 * formulaire, sinon il est refusé avec un warning. Cloudflare injoignable,
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
     * Action de chaque formulaire, donnée au widget et vérifiée ici : un
     * jeton ne sert que sur le formulaire qui l'a obtenu.
     */
    public const string ACTION_LOGIN = 'login';

    public const string ACTION_RESET_PASSWORD = 'reset-password';

    public const string ACTION_ACCESS_REQUEST = 'access-request';

    /**
     * Erreurs de configuration ou de Cloudflare, pas du jeton.
     */
    private const array REPORTED_ERROR_CODES = ['missing-input-secret', 'invalid-input-secret', 'internal-error', 'bad-request'];

    /**
     * Clé secrète de test « toujours valide » de Cloudflare (dev et tests) :
     * siteverify renvoie le hostname example.com et aucune action.
     */
    private const string TEST_SECRET_KEY = '1x0000000000000000000000000000000AA';

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
     * @param Request $request l'envoi du formulaire, qui porte le champ RESPONSE_FIELD
     * @param string  $action  l'une des constantes ACTION_*
     */
    public function verify(Request $request, string $action): bool
    {
        $token = $request->request->getString(self::RESPONSE_FIELD);

        // Sans JavaScript, le champ manque : pas besoin de Cloudflare pour refuser.
        if ('' === $token || \strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        $body = ['secret' => $this->secret, 'response' => $token];
        if (null !== $clientIp = $request->getClientIp()) {
            $body['remoteip'] = $clientIp;
        }

        try {
            $result = $this->turnstileClient->request('POST', '/turnstile/v0/siteverify', ['body' => $body])->toArray();
        } catch (HttpClientExceptionInterface $httpClientException) {
            return $this->outage($httpClientException::class.': '.$httpClientException->getMessage());
        }

        if (!\is_bool($result['success'] ?? null)) {
            return $this->outage('no success field in the response');
        }

        if ($result['success']) {
            return $this->matchesForm($result, $request, $action);
        }

        $errorCodes = \is_array($result['error-codes'] ?? null) ? array_values(array_filter($result['error-codes'], \is_string(...))) : [];
        if ([] !== array_intersect($errorCodes, self::REPORTED_ERROR_CODES)) {
            $this->logger->error('Turnstile siteverify reported an error: {error_codes}.', ['error_codes' => $errorCodes]);
        }

        return false;
    }

    /**
     * Un jeton validé par Cloudflare doit venir de cet hôte et de ce formulaire.
     *
     * @param array<mixed> $result
     */
    private function matchesForm(array $result, Request $request, string $action): bool
    {
        if (self::TEST_SECRET_KEY === $this->secret) {
            return true;
        }

        $hostname = $result['hostname'] ?? null;
        $tokenAction = $result['action'] ?? null;
        if (!\is_string($hostname) || !\is_string($tokenAction)) {
            return $this->outage('no hostname or action field in the response');
        }

        if ($hostname === $request->getHost() && $tokenAction === $action) {
            return true;
        }

        $this->logger->warning('Turnstile token refused: issued for {hostname} and {action}, expected {expected_hostname} and {expected_action}.', [
            'hostname' => $hostname,
            'expected_hostname' => $request->getHost(),
            'action' => $tokenAction,
            'expected_action' => $action,
        ]);

        return false;
    }

    private function outage(string $cause): bool
    {
        $this->logger->warning('Turnstile siteverify unavailable, token let through: {cause}.', ['cause' => $cause]);

        return true;
    }
}
