<?php

namespace App\Repository;

use App\Entity\EditorInteraction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EditorInteraction>
 *
 * @method EditorInteraction|null find($id, $lockMode = null, $lockVersion = null)
 * @method EditorInteraction|null findOneBy(array $criteria, array $orderBy = null)
 * @method EditorInteraction[]    findAll()
 * @method EditorInteraction[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EditorInteractionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EditorInteraction::class);
    }
}
