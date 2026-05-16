<?php

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 *
 * @method Project|null find($id, $lockMode = null, $lockVersion = null)
 * @method Project|null findOneBy(array $criteria, array $orderBy = null)
 * @method Project[]    findAll()
 * @method Project[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * Retourne tous les projets dont expires_at est défini et dépassé.
     *
     * @return Project[]
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.expiresAt IS NOT NULL')
            ->andWhere('p.expiresAt < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }
}

