<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Widget Cloudflare Turnstile d'un formulaire public (contrôleur Stimulus
 * turnstile). Le widget ajoute le champ cf-turnstile-response au formulaire
 * qui l'entoure, vérifié par TurnstileVerifier.
 */
#[AsTwigComponent]
final readonly class Turnstile
{
    public function __construct(
        #[Autowire(env: 'TURNSTILE_SITE_KEY')]
        public string $siteKey,
    ) {
    }
}
