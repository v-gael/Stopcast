<?php

declare(strict_types=1);

namespace App\Tests\Integration\Validator;

use App\Validator\PasswordRequirements;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Constraints\NotCompromisedPasswordValidator;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Le contrôle de fuite est désactivé dans le conteneur de test : le validateur
 * est donc assemblé ici, actif, devant une API Have I Been Pwned simulée.
 */
final class PasswordRequirementsTest extends TestCase
{
    /** Son empreinte SHA-1 est 2451F04D62956CF5E910CCE1F0E17D29E315D879. */
    private const string PASSWORD = 'motdepasse123';

    private const string LEAKED_HASH_SUFFIX = '04D62956CF5E910CCE1F0E17D29E315D879';

    private const string LEAK_MESSAGE = 'Ce mot de passe apparaît dans des fuites de données connues. Choisissez-en un autre.';

    public function testRefusesALeakedPassword(): void
    {
        $client = new MockHttpClient(new MockResponse(self::LEAKED_HASH_SUFFIX.":3\r\n"));

        self::assertSame([self::LEAK_MESSAGE], $this->violationMessages(self::PASSWORD, $client));
    }

    public function testAcceptsAPasswordAbsentFromTheLeaks(): void
    {
        $client = new MockHttpClient(new MockResponse("00000000000000000000000000000000000:3\r\n"));

        self::assertSame([], $this->violationMessages(self::PASSWORD, $client));
    }

    public function testAcceptsThePasswordWhenTheApiAnswersWithAnError(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        self::assertSame([], $this->violationMessages(self::PASSWORD, $client));
    }

    public function testAcceptsThePasswordWhenTheApiIsUnreachable(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Connexion impossible']));

        self::assertSame([], $this->violationMessages(self::PASSWORD, $client));
    }

    public function testDoesNotQueryTheApiForATooShortPassword(): void
    {
        $client = new MockHttpClient(new MockResponse(self::LEAKED_HASH_SUFFIX.":3\r\n"));

        self::assertSame(
            ['Le mot de passe doit faire au moins 12 caractères.'],
            $this->violationMessages('court', $client),
        );
        self::assertSame(0, $client->getRequestsCount());
    }

    /**
     * @return list<string>
     */
    private function violationMessages(string $password, HttpClientInterface $client): array
    {
        $validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                NotCompromisedPasswordValidator::class => new NotCompromisedPasswordValidator($client),
            ]))
            ->getValidator()
        ;

        $messages = [];

        foreach ($validator->validate($password, new PasswordRequirements()) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
