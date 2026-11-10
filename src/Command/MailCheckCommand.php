<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitoring\HealthcheckPinger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Lancée chaque mois par le worker : Brevo désactive une clé SMTP restée
 * 90 jours sans envoi réussi. Le mail part directement par le transport, sans
 * la file, pour que le ping ne parte que si Brevo l'a accepté.
 */
#[AsCommand(
    name: 'app:mail:check',
    description: 'Envoie un mail de contrôle pour garder la clé SMTP active',
)]
#[AsCronTask('0 4 1 * *', timezone: 'Europe/Paris')]
class MailCheckCommand extends Command
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly HealthcheckPinger $pinger,
        #[Autowire(env: 'MAILER_REPLY_TO')]
        private readonly string $recipient,
        #[Autowire(env: 'HEALTHCHECKS_MAIL_URL')]
        private readonly string $healthcheckUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->transport->send(new Email()
                ->to($this->recipient)
                ->subject("Contrôle mensuel de l'envoi des mails")
                ->text("Ce mail automatique vérifie chaque mois que Stopcast peut envoyer des mails.\nAucune action n'est nécessaire.\n"));
        } catch (\Throwable $throwable) {
            $this->pinger->pingFailure($this->healthcheckUrl);

            throw $throwable;
        }

        $this->pinger->ping($this->healthcheckUrl);

        new SymfonyStyle($input, $output)->success('Mail de contrôle accepté.');

        return Command::SUCCESS;
    }
}
