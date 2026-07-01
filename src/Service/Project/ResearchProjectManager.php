<?php

namespace App\Service\Project;

use App\Entity\ResearchProject;
use App\Entity\User;
use App\Repository\ResearchProjectRepository;
use App\Service\IA\DeepSeekService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class ResearchProjectManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ResearchProjectRepository $rpRepository,
        private ?DeepSeekService $deepSeekService = null,
        private ?RequestStack $requestStack = null
    ) {}

    /**
     * Crée un nouveau projet de recherche et le sauvegarde.
     */
    public function createForUser(User $user, string $title, ?string $description = null, bool $isTemplate = false): ResearchProject
    {
        $rp = new ResearchProject();
        $rp->setUser($user);
        $rp->setTitle($title);
        $rp->setDescription($description);
        $rp->setStatus('active');
        $rp->setIsTemplate($isTemplate);
        $rp->setCreatedAt(new \DateTime());

        // Génération automatique d'une synthèse et d'un plan de réalisation du projet via l'IA
        if ($this->deepSeekService) {
            try {
                if ($this->deepSeekService->isApiKeyPlaceholder()) {
                    // Fallback de test
                    $synthesis = "### Synthèse du Projet : " . $title . "\nCe projet a pour objectif d'étudier le sujet proposé.\n\n### Plan de réalisation proposé :\n1. **Phase 1 : Cadrage et revue bibliographique** (Mois 1)\n2. **Phase 2 : Méthodologie et collecte de données** (Mois 2-3)\n3. **Phase 3 : Analyse des résultats et rédaction** (Mois 4-6)";
                } else {
                    $prompt = sprintf(
                        "Tu es un expert en méthodologie de recherche scientifique.\nL'utilisateur vient de créer un nouveau projet de recherche intitulé \"%s\".\nVoici sa description :\n\"%s\"\n\nRédige une synthèse claire, structurée et rigoureuse de la problématique de ce projet, puis propose un plan de réalisation détaillé, étape par étape (phases, jalons clés, livrables attendus, tâches clés). Formatte ta réponse en Markdown de haute qualité.",
                        $title,
                        $description ?? '(Aucune description fournie)'
                    );
                    $synthesis = $this->deepSeekService->call($prompt, [
                        'system_prompt' => 'Tu es un assistant IA spécialisé dans l\'accompagnement et la méthodologie de recherche scientifique. Rédige une synthèse de projet et un plan de réalisation en Markdown.',
                        'temperature' => 0.6
                    ]);
                }
                $rp->setSynthesis($synthesis);
            } catch (\Exception $e) {
                $rp->setSynthesis("### Synthèse du projet\nErreur lors de la génération automatique : " . $e->getMessage());
            }
        }

        $this->entityManager->persist($rp);
        $this->entityManager->flush();

        return $rp;
    }

    /**
     * Wrapper pour la compatibilité avec l'ancien code.
     */
    public function createResearchProject(User $user, string $name, ?string $description = null): ResearchProject
    {
        return $this->createForUser($user, $name, $description);
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
     * Récupère tous les projets de recherche actifs (non archivés ni supprimés) d'un utilisateur.
     *
     * @return ResearchProject[]
     */
    public function getActiveProjectsForUser(User $user): array
    {
        return $this->rpRepository->findBy(
            [
                'user' => $user,
                'status' => 'active'
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
     * Alias de getResearchProject pour la spécification.
     */
    public function getProject(int $id): ?ResearchProject
    {
        return $this->getResearchProject($id);
    }

    /**
     * Met à jour un projet de recherche.
     */
    public function updateResearchProject(ResearchProject $rp, array $data): ResearchProject
    {
        if (isset($data['title'])) {
            $rp->setTitle($data['title']);
        } elseif (isset($data['name'])) {
            $rp->setName($data['name']);
        }

        if (array_key_exists('description', $data)) {
            $rp->setDescription($data['description']);
        }

        if (isset($data['status'])) {
            $rp->setStatus($data['status']);
        }

        if (isset($data['is_template'])) {
            $rp->setIsTemplate((bool)$data['is_template']);
        }

        $rp->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        return $rp;
    }

    /**
     * Alias de updateResearchProject pour la spécification.
     */
    public function updateProject(ResearchProject $rp, array $data): ResearchProject
    {
        return $this->updateResearchProject($rp, $data);
    }

    /**
     * Marque un projet de recherche comme supprimé (soft delete) et détache ses sous-projets.
     */
    public function deleteResearchProject(ResearchProject $rp): void
    {
        $rp->setStatus('deleted');
        $rp->setUpdatedAt(new \DateTime());
        
        // Dissocier les anciens projets liés pour la compatibilité
        foreach ($rp->getProjects() as $project) {
            $project->setResearchProject(null);
        }

        // Dissocier les nouveaux sous-projets liés
        foreach ($rp->getSubProjects() as $subProject) {
            $subProject->setResearchProject(null);
        }
        
        $this->entityManager->flush();
    }

    /**
     * Alias de deleteResearchProject pour la spécification.
     */
    public function deleteProject(ResearchProject $rp): void
    {
        $this->deleteResearchProject($rp);
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

    /**
     * Alias de archiveResearchProject pour la spécification.
     */
    public function archiveProject(ResearchProject $rp): void
    {
        $this->archiveResearchProject($rp);
    }

    /**
     * Définit le projet de recherche actif en session.
     */
    public function setActiveProject(User $user, ?ResearchProject $project): void
    {
        try {
            if ($this->requestStack === null) {
                return;
            }
            $session = $this->requestStack->getSession();
            if ($project === null) {
                $session->remove('active_research_project_id');
            } else {
                $session->set('active_research_project_id', $project->getId());
            }
        } catch (\Exception $e) {
            // Ignorer silencieusement si la session n'est pas démarrée (ex: CLI / tests)
        }
    }

    /**
     * Récupère la liste des sous-projets d'un projet de recherche.
     *
     * @return \App\Entity\SubProject[]
     */
    public function getSubProjects(ResearchProject $rp): array
    {
        return $rp->getSubProjects()->toArray();
    }
}
