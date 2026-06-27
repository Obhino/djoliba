<?php

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\ResearchProject;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;

class ProjectManager
{
    private EntityManagerInterface $entityManager;
    private ProjectRepository $projectRepository;

    public function __construct(EntityManagerInterface $entityManager, ProjectRepository $projectRepository)
    {
        $this->entityManager = $entityManager;
        $this->projectRepository = $projectRepository;
    }

    /**
     * Crée un nouveau projet et le sauvegarde en base de données.
     *
     * @param User $user
     * @param string $type ('literature_review', 'reading', 'writing', 'thesis')
     * @param string $name
     * @param ResearchProject|null $researchProject
     * @return Project
     */
    public function createProject(User $user, string $type, string $name, ?ResearchProject $researchProject = null): Project
    {
        $project = new Project();
        $project->setUser($user);
        $project->setType($type);
        $project->setName($name);
        $project->setStatus('active');
        $project->setCreatedAt(new \DateTime());
        $project->setLastAccessedAt(new \DateTime());
        $project->setResearchProject($researchProject);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /**
     * Récupère tous les projets non supprimés d'un utilisateur, éventuellement filtrés par type.
     *
     * @param User $user
     * @param string|null $type
     * @return Project[]
     */
    public function getUserProjects(User $user, ?string $type = null): array
    {
        $criteria = ['user' => $user];
        if ($type) {
            $criteria['type'] = $type;
        }

        return $this->projectRepository->findBy(
            $criteria,
            ['updatedAt' => 'DESC', 'createdAt' => 'DESC']
        );
    }

    /**
     * Récupère un projet spécifique par son ID.
     *
     * @param int $id
     * @return Project|null
     */
    public function getProject(int $id): ?Project
    {
        return $this->projectRepository->find($id);
    }

    /**
     * Met à jour les données d'un projet.
     *
     * @param Project $project
     * @param array $data Données à mettre à jour (ex: ['name' => 'Nouveau nom', 'metadata' => [...]])
     * @return Project
     */
    public function updateProject(Project $project, array $data): Project
    {
        if (isset($data['name'])) {
            $project->setName($data['name']);
        }
        
        if (isset($data['type'])) {
            $project->setType($data['type']);
        }

        if (isset($data['metadata'])) {
            // Fusion des métadonnées existantes avec les nouvelles
            $currentMetadata = $project->getMetadata() ?? [];
            $project->setMetadata(array_merge($currentMetadata, $data['metadata']));
        }

        $project->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        return $project;
    }

    /**
     * Supprime définitivement un projet (ou le marque comme supprimé).
     *
     * @param Project $project
     */
    public function deleteProject(Project $project): void
    {
        // On pourrait aussi faire un soft delete en changeant le status à 'deleted'
        // $project->setStatus('deleted');
        
        $this->entityManager->remove($project);
        $this->entityManager->flush();
    }

    /**
     * Archive un projet pour qu'il ne soit plus actif.
     *
     * @param Project $project
     */
    public function archiveProject(Project $project): void
    {
        $project->setStatus('archived');
        $project->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
    }
}
