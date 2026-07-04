<?php

namespace App\Repository;

use App\Entity\BibliographyEntry;
use App\Entity\SubProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BibliographyEntry>
 *
 * @method BibliographyEntry|null find($id, $lockMode = null, $lockVersion = null)
 * @method BibliographyEntry|null findOneBy(array $criteria, array $orderBy = null)
 * @method BibliographyEntry[]    findAll()
 * @method BibliographyEntry[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BibliographyEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BibliographyEntry::class);
    }

    /**
     * Retourne toutes les entrées d'un SubProject, triées par auteur puis année.
     *
     * @return BibliographyEntry[]
     */
    public function findBySubProject(SubProject $subProject): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.subProject = :sp')
            ->setParameter('sp', $subProject)
            ->orderBy('b.authors', 'ASC')
            ->addOrderBy('b.year', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche full-text simple dans titre, auteurs ou citeKey.
     *
     * @return BibliographyEntry[]
     */
    public function searchBySubProject(SubProject $subProject, string $query): array
    {
        $like = '%' . mb_strtolower($query) . '%';

        return $this->createQueryBuilder('b')
            ->where('b.subProject = :sp')
            ->andWhere(
                'LOWER(b.title) LIKE :q OR LOWER(b.authors) LIKE :q OR LOWER(b.citeKey) LIKE :q OR LOWER(b.journal) LIKE :q'
            )
            ->setParameter('sp', $subProject)
            ->setParameter('q', $like)
            ->orderBy('b.authors', 'ASC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie si une clé BibTeX existe déjà dans le projet.
     */
    public function findByCiteKey(SubProject $subProject, string $citeKey): ?BibliographyEntry
    {
        return $this->findOneBy([
            'subProject' => $subProject,
            'citeKey'    => $citeKey,
        ]);
    }

    /**
     * Supprime toutes les entrées d'un SubProject.
     */
    public function deleteAllForSubProject(SubProject $subProject): int
    {
        return $this->createQueryBuilder('b')
            ->delete()
            ->where('b.subProject = :sp')
            ->setParameter('sp', $subProject)
            ->getQuery()
            ->execute();
    }

    /**
     * Compte le nombre de références d'un SubProject.
     */
    public function countBySubProject(SubProject $subProject): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.subProject = :sp')
            ->setParameter('sp', $subProject)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
