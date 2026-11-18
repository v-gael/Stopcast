<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\TurnstileVerifier;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class TurnstileVerifierTest extends TestCase
{
    private const string SECRET = 'secret-key';

    private const string TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

    /**
     * Un hôte de TRUSTED_HOSTS (.env) : la liste, statique dans Request,
     * reste celle des tests du kernel lancés avant.
     */
    private const string HOST = 'localhost';

    private const string CLIENT_IP = '203.0.113.7';

    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testASuccessfulSiteverifyAcceptsTheToken(): void
    {
        $response = self::siteverify(['success' => true, 'hostname' => self::HOST, 'action' => TurnstileVerifier::ACTION_LOGIN]);

        self::assertTrue($this->verifier($response)->verify($this->request(), TurnstileVerifier::ACTION_LOGIN));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $response->getRequestUrl());
        $requestBody = $response->getRequestOptions()['body'];
        self::assertIsString($requestBody);
        parse_str($requestBody, $body);
        self::assertSame(['secret' => self::SECRET, 'response' => self::TOKEN, 'remoteip' => self::CLIENT_IP], $body);
        self::assertSame([], $this->logs->getRecords());
    }

    public function testAnUnknownClientIpIsNotSent(): void
    {
        $response = self::siteverify(['success' => true, 'hostname' => self::HOST, 'action' => TurnstileVerifier::ACTION_LOGIN]);

        self::assertTrue($this->verifier($response)->verify($this->request(clientIp: null), TurnstileVerifier::ACTION_LOGIN));

        $requestBody = $response->getRequestOptions()['body'];
        self::assertIsString($requestBody);
        parse_str($requestBody, $body);
        self::assertSame(['secret' => self::SECRET, 'response' => self::TOKEN], $body);
    }

    /**
     * Un jeton obtenu sur un autre site, envoyé ici : Cloudflare le valide,
     * mais pour l'hôte où le widget l'a délivré.
     */
    public function testATokenIssuedForAnotherHostIsRefusedWithAWarning(): void
    {
        $response = self::siteverify(['success' => true, 'hostname' => 'piege.example', 'action' => TurnstileVerifier::ACTION_LOGIN]);

        self::assertFalse($this->verifier($response)->verify($this->request(), TurnstileVerifier::ACTION_LOGIN));

        self::assertCount(1, $this->logs->getRecords());
        $record = $this->logs->getRecords()[0];
        self::assertSame(Level::Warning, $record->level);
        self::assertSame(['hostname' => 'piege.example', 'expected_hostname' => self::HOST, 'action' => TurnstileVerifier::ACTION_LOGIN, 'expected_action' => TurnstileVerifier::ACTION_LOGIN], $record->context);
        self::assertStringNotContainsString(self::TOKEN, json_encode($record->toArray(), \JSON_THROW_ON_ERROR));
    }

    /**
     * Un jeton obtenu sur un autre formulaire du site ne sert pas ici.
     */
    public function testATokenIssuedForAnotherFormIsRefusedWithAWarning(): void
    {
        $response = self::siteverify(['success' => true, 'hostname' => self::HOST, 'action' => TurnstileVerifier::ACTION_ACCESS_REQUEST]);

        self::assertFalse($this->verifier($response)->verify($this->request(), TurnstileVerifier::ACTION_LOGIN));

        self::assertCount(1, $this->logs->getRecords());
        $record = $this->logs->getRecords()[0];
        self::assertSame(Level::Warning, $record->level);
        self::assertSame(['hostname' => self::HOST, 'expected_hostname' => self::HOST, 'action' => TurnstileVerifier::ACTION_ACCESS_REQUEST, 'expected_action' => TurnstileVerifier::ACTION_LOGIN], $record->context);
    }

    /**
     * La clé secrète de test « toujours valide », celle du dev et des tests,
     * renvoie le hostname example.com et aucune action.
     */
    public function testTheTestSecretKeySkipsTheHostnameAndActionChecks(): void
    {
        $response = self::siteverify(['success' => true, 'hostname' => 'example.com']);
        $verifier = new TurnstileVerifier(
            new MockHttpClient($response, 'https://challenges.cloudflare.com'),
            new Logger('app', [$this->logs]),
            '1x0000000000000000000000000000000AA',
        );

        self::assertTrue($verifier->verify($this->request(), TurnstileVerifier::ACTION_LOGIN));

        self::assertSame([], $this->logs->getRecords());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rejectedTokenCodes(): iterable
    {
        yield 'jeton invalide ou expiré' => [['invalid-input-response']];
        yield 'jeton déjà utilisé' => [['timeout-or-duplicate']];
        yield 'sans code' => [[]];
    }

    /**
     * @param list<string> $errorCodes
     */
    #[DataProvider('rejectedTokenCodes')]
    public function testARejectedTokenIsRefusedWithoutLogging(array $errorCodes): void
    {
        self::assertFalse($this->verifier(self::siteverify(['success' => false, 'error-codes' => $errorCodes]))->verify($this->request(), TurnstileVerifier::ACTION_LOGIN));

        self::assertSame([], $this->logs->getRecords());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function reportedErrorCodes(): iterable
    {
        yield 'secret absent' => [['missing-input-secret']];
        yield 'secret faux' => [['invalid-input-secret']];
        yield 'erreur interne' => [['internal-error']];
        yield 'requête malformée' => [['bad-request']];
        yield 'avec un code de jeton' => [['invalid-input-secret', 'invalid-input-response']];
    }

    /**
     * Une clé secrète fausse en prod refuse toutes les connexions : l'erreur
     * remonte dans Sentry, avec les codes de Cloudflare et sans le jeton.
     *
     * @param list<string> $errorCodes
     */
    #[DataProvider('reportedErrorCodes')]
    public function testAnErrorReportedByCloudflareIsRefusedAndLoggedAsAnError(array $errorCodes): void
    {
        self::assertFalse($this->verifier(self::siteverify(['success' => false, 'error-codes' => $errorCodes]))->verify($this->request(), TurnstileVerifier::ACTION_LOGIN));

        self::assertCount(1, $this->logs->getRecords());
        $record = $this->logs->getRecords()[0];
        self::assertSame(Level::Error, $record->level);
        self::assertSame($errorCodes, $record->context['error_codes']);
        self::assertStringNotContainsString(self::TOKEN, json_encode($record->toArray(), \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function outages(): iterable
    {
        yield 'erreur serveur' => [new MockResponse('', ['http_code' => 500])];
        yield 'transport' => [new MockResponse('', ['error' => 'Could not resolve host'])];
        yield 'délai dépassé' => [new MockResponse([''], ['error' => 'Idle timeout reached'])];
        yield 'réponse illisible' => [new MockResponse('<html>', ['response_headers' => ['content-type' => 'text/html']])];
        yield 'success absent' => [self::siteverify(['error-codes' => []])];
        yield 'hostname absent' => [self::siteverify(['success' => true, 'action' => TurnstileVerifier::ACTION_LOGIN])];
        yield 'action absente' => [self::siteverify(['success' => true, 'hostname' => self::HOST])];
        yield 'hostname illisible' => [self::siteverify(['success' => true, 'hostname' => 42, 'action' => TurnstileVerifier::ACTION_LOGIN])];
    }

    /**
     * Cloudflare injoignable : les limites de débit restent la seule
     * protection, le formulaire n'est pas bloqué.
     */
    #[DataProvider('outages')]
    public function testAnOutageLetsTheTokenThroughWithAWarning(MockResponse $response): void
    {
        self::assertTrue($this->verifier($response)->verify($this->request(), TurnstileVerifier::ACTION_LOGIN));

        self::assertCount(1, $this->logs->getRecords());
        $record = $this->logs->getRecords()[0];
        self::assertSame(Level::Warning, $record->level);
        self::assertStringNotContainsString(self::TOKEN, json_encode($record->toArray(), \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableTokens(): iterable
    {
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'plus long que les 2048 caractères de Turnstile' => [str_repeat('a', 2049)];
    }

    /**
     * Sans JavaScript, le champ n'existe pas : ce n'est pas une panne.
     */
    #[DataProvider('unusableTokens')]
    public function testAnUnusableTokenIsRefusedWithoutCallingCloudflare(?string $token): void
    {
        $client = new MockHttpClient(static fn (): never => self::fail('Cloudflare ne doit pas être appelé.'));
        $verifier = new TurnstileVerifier($client, new Logger('app', [$this->logs]), self::SECRET);

        self::assertFalse($verifier->verify($this->request($token), TurnstileVerifier::ACTION_LOGIN));

        self::assertSame(0, $client->getRequestsCount());
        self::assertSame([], $this->logs->getRecords());
    }

    /**
     * Envoi d'un formulaire sur HOST, depuis CLIENT_IP par défaut.
     */
    private function request(?string $token = self::TOKEN, ?string $clientIp = self::CLIENT_IP): Request
    {
        $parameters = null === $token ? [] : [TurnstileVerifier::RESPONSE_FIELD => $token];

        return Request::create('https://'.self::HOST.'/login', 'POST', $parameters, server: ['REMOTE_ADDR' => $clientIp]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function siteverify(array $payload): MockResponse
    {
        return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    private function verifier(MockResponse $response): TurnstileVerifier
    {
        return new TurnstileVerifier(
            new MockHttpClient($response, 'https://challenges.cloudflare.com'),
            new Logger('app', [$this->logs]),
            self::SECRET,
        );
    }
}
