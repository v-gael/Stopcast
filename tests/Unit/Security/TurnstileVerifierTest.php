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

final class TurnstileVerifierTest extends TestCase
{
    private const string SECRET = 'secret-key';

    private const string TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testASuccessfulSiteverifyAcceptsTheToken(): void
    {
        $response = self::siteverify(['success' => true]);

        self::assertTrue($this->verifier($response)->verify(self::TOKEN));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $response->getRequestUrl());
        $requestBody = $response->getRequestOptions()['body'];
        self::assertIsString($requestBody);
        parse_str($requestBody, $body);
        self::assertSame(['secret' => self::SECRET, 'response' => self::TOKEN], $body);
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
        self::assertFalse($this->verifier(self::siteverify(['success' => false, 'error-codes' => $errorCodes]))->verify(self::TOKEN));

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
        self::assertFalse($this->verifier(self::siteverify(['success' => false, 'error-codes' => $errorCodes]))->verify(self::TOKEN));

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
    }

    /**
     * Cloudflare injoignable : les limites de débit restent la seule
     * protection, le formulaire n'est pas bloqué.
     */
    #[DataProvider('outages')]
    public function testAnOutageLetsTheTokenThroughWithAWarning(MockResponse $response): void
    {
        self::assertTrue($this->verifier($response)->verify(self::TOKEN));

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

        self::assertFalse($verifier->verify($token));

        self::assertSame(0, $client->getRequestsCount());
        self::assertSame([], $this->logs->getRecords());
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
