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
        $legacyProject->setSubProject($subProject);

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
     * @return SubProject[]
     */
    public function getSubProjectsForUser(User $user, ?string $type = null): array
    {
        $criteria = [
            'user' => $user,
            'status' => ['active', 'archived']
        ];

        if ($type !== null) {
            $criteria['type'] = $type;
        }

        return $this->subProjectRepository->findBy(
            $criteria,
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Récupère les sous-projets d'un projet de recherche.
     *
     * @return SubProject[]
     */
    public function getSubProjectsForProject(ResearchProject $researchProject): array
    {
        return $this->subProjectRepository->findBy(
            [
                'researchProject' => $researchProject,
                'status' => ['active', 'archived']
            ],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Récupère les sous-projets orphelins (sans projet de recherche parent) d'un utilisateur.
     *
     * @return SubProject[]
     */
    public function getOrphanSubProjectsForUser(User $user, ?string $type = null): array
    {
        $criteria = [
            'user' => $user,
            'researchProject' => null,
            'status' => ['active', 'archived']
        ];

        if ($type !== null) {
            $criteria['type'] = $type;
        }

        return $this->subProjectRepository->findBy(
            $criteria,
            ['createdAt' => 'DESC']
        );
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
