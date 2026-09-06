<?php

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

    /**
     * @return Route[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['active' => true], ['shortName' => 'ASC']);
    }
}
