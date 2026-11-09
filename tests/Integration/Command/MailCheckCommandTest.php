<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\MailCheckCommand;
use App\Tests\Support\ContainerServiceTrait;
use App\Tests\Support\HealthcheckPingsTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final class MailCheckCommandTest extends KernelTestCase
{
    use ContainerServiceTrait;
    use HealthcheckPingsTrait;

    private const string PING_URL = 'https://hc-ping.test/mail';

    private const string RECIPIENT = 'contact@stopcast.test';

    public function testAnAcceptedMailIsPlainTextForTheContactAddressAndPingsHealthchecks(): void
    {
        $tester = $this->command($this->service(TransportInterface::class));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(self::RECIPIENT, $email->getTo()[0]->getAddress());
        self::assertNotEmpty($email->getFrom());
        self::assertNull($email->getHtmlBody());
        self::assertNotNull($email->getTextBody());
        self::assertSame([self::PING_URL], $this->pings);
    }

    public function testARefusedMailPingsTheFailureUrlAndKeepsTheError(): void
    {
        $failure = new TransportException('Clé SMTP désactivée');

        $this->assertTheCheckRethrows($this->command($this->refusingTransport($failure)), $failure);

        self::assertSame([self::PING_URL.'/fail'], $this->pings);
    }

    public function testAnEmptyHealthcheckUrlSendsNoPingWhenTheMailIsAccepted(): void
    {
        $tester = $this->command($this->service(TransportInterface::class), pingUrl: '');

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([], $this->pings);
    }

    public function testAnEmptyHealthcheckUrlSendsNoPingWhenTheMailIsRefused(): void
    {
        $failure = new TransportException('Clé SMTP désactivée');

        $this->assertTheCheckRethrows($this->command($this->refusingTransport($failure), pingUrl: ''), $failure);

        self::assertSame([], $this->pings);
    }

    private function assertTheCheckRethrows(CommandTester $tester, TransportException $failure): void
    {
        try {
            $tester->execute([]);
            self::fail('Le contrôle aurait dû échouer.');
        } catch (TransportException $transportException) {
            self::assertSame($failure, $transportException);
        }
    }

    private function refusingTransport(TransportException $failure): TransportInterface
    {
        $transport = self::createStub(TransportInterface::class);
        $transport->method('send')->willThrowException($failure);

        return $transport;
    }

    private function command(TransportInterface $transport, string $pingUrl = self::PING_URL): CommandTester
    {
        return new CommandTester(new MailCheckCommand(
            $transport,
            $this->recordingPinger(),
            self::RECIPIENT,
            $pingUrl,
        ));
    }
}
