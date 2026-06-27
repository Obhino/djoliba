<?php

namespace App\Repository;

use App\Entity\ResearchProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResearchProject>
 *
 * @method ResearchProject|null find($id, $lockMode = null, $lockVersion = null)
 * @method ResearchProject|null findOneBy(array $criteria, array $orderBy = null)
 * @method ResearchProject[]    findAll()
 * @method ResearchProject[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ResearchProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResearchProject::class);
    }
}
