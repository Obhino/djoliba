<?php

namespace App\Service\Project;

use App\Entity\ResearchProject;
use App\Entity\User;
use App\Repository\ResearchProjectRepository;
use Doctrine\ORM\EntityManagerInterface;

class ResearchProjectManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ResearchProjectRepository $rpRepository
    ) {}

    /**
     * Crée un nouveau projet de recherche et le sauvegarde.
     */
    public function createResearchProject(User $user, string $name, ?string $description = null): ResearchProject
    {
        $rp = new ResearchProject();
        $rp->setUser($user);
        $rp->setName($name);
        $rp->setDescription($description);
        $rp->setStatus('active');
        $rp->setCreatedAt(new \DateTime());

        $this->entityManager->persist($rp);
        $this->entityManager->flush();

        return $rp;
    }

    /**
     * Récupère tous les projets de recherche non supprimés d'un utilisateur.
     *
     * @return ResearchProject[]
     */
    public function getUserResearchProjects(User $user): array
    {
        return $this->rpRepository->findBy(
            [
                'user' => $user,
                'status' => ['active', 'archived']
            ],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Récupère un projet de recherche spécifique par son ID.
     */
    public function getResearchProject(int $id): ?ResearchProject
    {
        return $this->rpRepository->find($id);
    }

    /**
     * Met à jour un projet de recherche.
     */
    public function updateResearchProject(ResearchProject $rp, array $data): ResearchProject
    {
        if (isset($data['name'])) {
            $rp->setName($data['name']);
        }

        if (array_key_exists('description', $data)) {
            $rp->setDescription($data['description']);
        }

        if (isset($data['status'])) {
            $rp->setStatus($data['status']);
        }

        $rp->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        return $rp;
    }

    /**
     * Marque un projet de recherche comme supprimé (soft delete).
     */
    public function deleteResearchProject(ResearchProject $rp): void
    {
        $rp->setStatus('deleted');
        $rp->setUpdatedAt(new \DateTime());
        
        // Dissocier optionnellement les sous-projets ou les soft-deleter également?
        // Pour être sûr de ne rien casser et garder les projets accessibles (ou indépendants),
        // on peut soit les laisser associés mais cachés, soit les détacher.
        // Laissons-les associés pour historique, ou détachons-les.
        // Option la plus sûre : détacher les sous-projets pour qu'ils redeviennent des projets indépendants.
        // "les nouveaux projets peuvent être rattachés à un ResearchProject (ou rester indépendants)"
        // En les détachant, ils ne sont pas supprimés, ce qui respecte la règle de ne pas supprimer/modifier l'existant.
        foreach ($rp->getProjects() as $project) {
            $project->setResearchProject(null);
        }
        
        $this->entityManager->flush();
    }

    /**
     * Archive un projet de recherche.
     */
    public function archiveResearchProject(ResearchProject $rp): void
    {
        $rp->setStatus('archived');
        $rp->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();
    }
}
