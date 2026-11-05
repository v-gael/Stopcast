<?php

declare(strict_types=1);

namespace App\Logging;

use Sentry\Event;

/**
 * before_send de Sentry : masque IP, emails et jetons de réinitialisation
 * restés dans un événement (message, exceptions, données ajoutées, contextes,
 * tags, requête), comme PersonalDataMaskingProcessor le fait pour les logs.
 * L'utilisateur est retiré : data_collection ne le collecte déjà pas, ceci
 * couvre un ajout par un autre chemin.
 */
final readonly class SentryEventScrubber
{
    public function __construct(
        private PersonalDataMasker $masker,
    ) {
    }

    public function __invoke(Event $event): Event
    {
        $message = $event->getMessage();
        if (null !== $message) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(
                $this->masker->mask($message),
                array_map($this->masker->mask(...), $event->getMessageParams()),
                null === $formatted ? null : $this->masker->mask($formatted),
            );
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue($this->masker->mask($exception->getValue()));
        }

        $event->setExtra($this->masker->maskValues($event->getExtra()));
        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, $this->masker->maskValues($context));
        }

        $event->setUser(null);
        $event->setTags(array_map($this->masker->mask(...), $event->getTags()));
        $event->setRequest($this->masker->maskValues($event->getRequest()));

        return $event;
    }
}
