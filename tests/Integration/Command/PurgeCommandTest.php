<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Account\DataPurger;
use App\Command\PurgeCommand;
use App\Tests\Support\ContainerServiceTrait;
use App\Tests\Support\HealthcheckPingsTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PurgeCommandTest extends KernelTestCase
{
    use ContainerServiceTrait;
    use HealthcheckPingsTrait;
    use ResetDatabase;

    private const string PING_URL = 'https://hc-ping.test/purge';

    public function testASuccessfulPurgePingsHealthchecks(): void
    {
        $tester = $this->command();

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([self::PING_URL], $this->pings);
    }

    public function testAFailedPurgePingsTheFailureUrlAndKeepsTheError(): void
    {
        $failure = $this->breakThePurge();

        $this->assertThePurgeRethrows($this->command(), $failure);

        self::assertSame([self::PING_URL.'/fail'], $this->pings);
    }

    public function testAnEmptyHealthcheckUrlSendsNoPingWhenThePurgeSucceeds(): void
    {
        $tester = $this->command(pingUrl: '');

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([], $this->pings);
    }

    public function testAnEmptyHealthcheckUrlSendsNoPingWhenThePurgeFails(): void
    {
        $failure = $this->breakThePurge();

        $this->assertThePurgeRethrows($this->command(pingUrl: ''), $failure);

        self::assertSame([], $this->pings);
    }

    private function assertThePurgeRethrows(CommandTester $tester, \RuntimeException $failure): void
    {
        try {
            $tester->execute([]);
            self::fail('La purge aurait dû échouer.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }
    }

    /**
     * Dernière étape de la purge en panne : DataPurger lève cette erreur.
     */
    private function breakThePurge(): \RuntimeException
    {
        $failure = new \RuntimeException('File des messages en échec illisible');
        $failedTransport = self::createStub(ListableReceiverInterface::class);
        $failedTransport->method('all')->willThrowException($failure);
        self::getContainer()->set('messenger.transport.failed', $failedTransport);

        return $failure;
    }

    private function command(string $pingUrl = self::PING_URL): CommandTester
    {
        return new CommandTester(new PurgeCommand(
            $this->service(DataPurger::class),
            $this->recordingPinger(),
            $pingUrl,
        ));
    }
}
