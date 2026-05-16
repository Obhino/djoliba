<?php

namespace App\Repository;

use App\Entity\DailyMetrics;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyMetrics>
 *
 * @method DailyMetrics|null find($id, $lockMode = null, $lockVersion = null)
 * @method DailyMetrics|null findOneBy(array $criteria, array $orderBy = null)
 * @method DailyMetrics[]    findAll()
 * @method DailyMetrics[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DailyMetricsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyMetrics::class);
    }
}
