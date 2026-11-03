<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;

final class ProductionHealthcheckTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    public function testTheExampleTrustedHostsAcceptTheHealthcheckHost(): void
    {
        $host = $this->healthcheckHost();
        $env = new Dotenv()->parse((string) file_get_contents(self::ROOT.'/.env.prod.local.example'));
        $trustedHosts = $env['TRUSTED_HOSTS'] ?? '';
        self::assertIsString($trustedHosts);
        $patterns = array_filter(explode(',', $trustedHosts));

        self::assertNotEmpty($patterns, '.env.prod.local.example doit renseigner TRUSTED_HOSTS.');
        // Même correspondance que Request::setTrustedHosts().
        $accepted = array_filter($patterns, static fn (string $pattern): bool => 1 === preg_match(\sprintf('{%s}i', $pattern), $host));
        self::assertNotEmpty(
            $accepted,
            \sprintf('Le healthcheck de app interroge %s : TRUSTED_HOSTS doit l\'accepter, sinon il reçoit une 400 et le conteneur passe unhealthy.', $host),
        );
    }

    private function healthcheckHost(): string
    {
        // PARSE_CUSTOM_TAGS : compose.prod.yaml utilise le tag Compose !reset.
        $compose = Yaml::parseFile(self::ROOT.'/compose.prod.yaml', Yaml::PARSE_CUSTOM_TAGS);
        $test = $this->at($compose, 'services', 'app', 'healthcheck', 'test');
        self::assertIsArray($test);

        $urls = array_values(array_filter($test, static fn (mixed $arg): bool => \is_string($arg) && str_starts_with($arg, 'http')));
        self::assertCount(1, $urls, 'Le healthcheck de app dans compose.prod.yaml doit appeler une URL.');

        $host = parse_url($urls[0], \PHP_URL_HOST);
        self::assertIsString($host);

        return $host;
    }

    private function at(mixed $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data, \sprintf('compose.prod.yaml : clé %s introuvable.', $key));
            $data = $data[$key];
        }

        return $data;
    }
}
