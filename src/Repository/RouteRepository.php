<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Route;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Route>
 */
class RouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Route::class);
    }

    public function findOneByGtfsRouteId(string $gtfsRouteId): ?Route
    {
        return $this->findOneBy(['gtfsRouteId' => $gtfsRouteId]);
    }
}
