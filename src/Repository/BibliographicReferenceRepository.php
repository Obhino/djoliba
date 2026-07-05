<?php

namespace App\Repository;

use App\Entity\BibliographicReference;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BibliographicReference>
 *
 * @method BibliographicReference|null find($id, $lockMode = null, $lockVersion = null)
 * @method BibliographicReference|null findOneBy(array $criteria, array $orderBy = null)
 * @method BibliographicReference[]    findAll()
 * @method BibliographicReference[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BibliographicReferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BibliographicReference::class);
    }

    /**
     * Recherche full-text simple dans titre, auteurs, citeKey ou journal d'un utilisateur.
     *
     * @return BibliographicReference[]
     */
    public function searchByUser(User $user, string $query): array
    {
        $like = '%' . mb_strtolower($query) . '%';

        return $this->createQueryBuilder('b')
            ->where('b.user = :user')
            ->andWhere(
                'LOWER(b.title) LIKE :q OR LOWER(b.authors) LIKE :q OR LOWER(b.citeKey) LIKE :q OR LOWER(b.journal) LIKE :q'
            )
            ->setParameter('user', $user)
            ->setParameter('q', $like)
            ->orderBy('b.authors', 'ASC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }
}
