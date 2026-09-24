<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Dto\GtfsRt\DisplayItem;
use App\State\NextPassagesProvider;

/**
 * Réponse consommée par le Raspberry Pi : un seul appel, authentifié par token
 * (header X-Device-Token), qui renvoie tous les affichages actifs du device
 * avec leurs prochains passages.
 *
 * Pas d'identifiant dans l'URL : le device est résolu à partir du token,
 * pas d'un {id} de la route.
 *
 * Pas de flag "realtime indisponible" : si le flux RT échoue, l'erreur remonte
 * telle quelle (réponse HTTP en erreur) plutôt que de renvoyer une réponse
 * partielle — le Pi ne récupère alors rien et garde son data.json précédent.
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/next-passages',
            paginationEnabled: false,
            provider: NextPassagesProvider::class,
        ),
    ],
)]
final readonly class NextPassagesResponse
{
    /**
     * @param DisplayItem[] $displays
     */
    public function __construct(
        public \DateTimeImmutable $generatedAt,
        public array $displays,
    ) {
    }
}
