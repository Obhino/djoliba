<?php

namespace App\Repository;

use App\Entity\ProjectBibliography;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectBibliography>
 *
 * @method ProjectBibliography|null find($id, $lockMode = null, $lockVersion = null)
 * @method ProjectBibliography|null findOneBy(array $criteria, array $orderBy = null)
 * @method ProjectBibliography[]    findAll()
 * @method ProjectBibliography[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProjectBibliographyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectBibliography::class);
    }
}
