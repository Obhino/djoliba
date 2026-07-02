<?php

namespace App\Service\Project;

use App\Entity\ResearchProject;
use App\Entity\SubProject;
use App\Entity\User;
use App\Repository\SubProjectRepository;
use Doctrine\ORM\EntityManagerInterface;

class SubProjectManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SubProjectRepository $subProjectRepository
    ) {}

    /**
     * Crée un nouveau sous-projet et le persiste en base.
     */
    public function createForUser(
        User $user,
        string $type,
        string $name,
        ?ResearchProject $researchProject = null,
        ?string $content = null,
        ?array $metadata = null
    ): SubProject {
        $subProject = new SubProject();
        $subProject->setUser($user);
        $subProject->setType($type);
        $subProject->setName($name);
        $subProject->setResearchProject($researchProject);
        $subProject->setContent($content);
        $subProject->setMetadata($metadata);
        $subProject->setStatus('active');
        $subProject->setCreatedAt(new \DateTime());

        $this->entityManager->persist($subProject);
        $this->entityManager->flush();

        // Créer un projet hérité compagnon pour maintenir la compatibilité
        $legacyProject = new \App\Entity\Project();
        $legacyProject->setUser($user);
        
        $legacyType = $type;
        if ($type === 'literature') {
            $legacyType = 'literature_review';
        }
        $legacyProject->setType($legacyType);
        $legacyProject->setName($name);
        $legacyProject->setStatus('active');
        $legacyProject->setCreatedAt(new \DateTime());
        $legacyProject->setResearchProject($researchProject);
        $subProject->addProject($legacyProject);

        $this->entityManager->persist($legacyProject);
        $this->entityManager->flush();

        return $subProject;
    }

    /**
     * Met à jour un sous-projet existant.
     */
    public function updateSubProject(SubProject $subProject, array $data): SubProject
    {
        if (isset($data['name'])) {
            $subProject->setName($data['name']);
        }

        if (array_key_exists('content', $data)) {
            $subProject->setContent($data['content']);
        }

        if (isset($data['status'])) {
            $subProject->setStatus($data['status']);
        }

        if (isset($data['metadata'])) {
            $currentMetadata = $subProject->getMetadata() ?? [];
            $subProject->setMetadata(array_merge($currentMetadata, $data['metadata']));
        }

        $subProject->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        return $subProject;
    }

    /**
     * Supprime un sous-projet.
     */
    public function deleteSubProject(SubProject $subProject): void
    {
        // Supprime le sous-projet
        $this->entityManager->remove($subProject);
        $this->entityManager->flush();
    }

    /**
     * Récupère tous les sous-projets non supprimés d'un utilisateur, éventuellement filtrés par type.
     *
     * @return array
     */
    public function getSubProjectsForUser(User $user, ?string $type = null, ?int $page = null, ?int $limit = null): array
    {
        $qb = $this->subProjectRepository->createQueryBuilder('sp')
            ->where('sp.user = :user')
            ->andWhere('sp.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['active', 'archived'])
            ->orderBy('sp.createdAt', 'DESC');

        if ($type !== null) {
            $qb->andWhere('sp.type = :type')
               ->setParameter('type', $type);
        }

        if ($page !== null && $limit !== null) {
            $qb->setFirstResult(($page - 1) * $limit)
               ->setMaxResults($limit);

            $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($qb);
            $total = count($paginator);

            return [
                'items' => iterator_to_array($paginator),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit)
            ];
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les sous-projets d'un projet de recherche.
     *
     * @return array
     */
    public function getSubProjectsForProject(ResearchProject $researchProject, ?string $type = null, ?int $page = null, ?int $limit = null): array
    {
        $qb = $this->subProjectRepository->createQueryBuilder('sp')
            ->where('sp.researchProject = :researchProject')
            ->andWhere('sp.status IN (:statuses)')
            ->setParameter('researchProject', $researchProject)
            ->setParameter('statuses', ['active', 'archived'])
            ->orderBy('sp.createdAt', 'DESC');

        if ($type !== null) {
            $qb->andWhere('sp.type = :type')
               ->setParameter('type', $type);
        }

        if ($page !== null && $limit !== null) {
            $qb->setFirstResult(($page - 1) * $limit)
               ->setMaxResults($limit);

            $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($qb);
            $total = count($paginator);

            return [
                'items' => iterator_to_array($paginator),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit)
            ];
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les sous-projets orphelins (sans projet de recherche parent) d'un utilisateur.
     *
     * @return array
     */
    public function getOrphanSubProjectsForUser(User $user, ?string $type = null, ?int $page = null, ?int $limit = null): array
    {
        $qb = $this->subProjectRepository->createQueryBuilder('sp')
            ->where('sp.user = :user')
            ->andWhere('sp.researchProject IS NULL')
            ->andWhere('sp.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['active', 'archived'])
            ->orderBy('sp.createdAt', 'DESC');

        if ($type !== null) {
            $qb->andWhere('sp.type = :type')
               ->setParameter('type', $type);
        }

        if ($page !== null && $limit !== null) {
            $qb->setFirstResult(($page - 1) * $limit)
               ->setMaxResults($limit);

            $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($qb);
            $total = count($paginator);

            return [
                'items' => iterator_to_array($paginator),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit)
            ];
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Rattache un sous-projet à un projet de recherche parent.
     */
    public function attachToProject(SubProject $subProject, ResearchProject $researchProject): void
    {
        $subProject->setResearchProject($researchProject);
        $subProject->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();
    }

    /**
     * Détache un sous-projet de son projet de recherche parent.
     */
    public function detachFromProject(SubProject $subProject): void
    {
        $subProject->setResearchProject(null);
        $subProject->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();
    }
}
