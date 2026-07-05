<?php

namespace App\Controller;

use App\Entity\ResearchProject;
use App\Entity\SubProject;
use App\Service\Bibliography\BibliographicReferenceManager;
use App\Service\Project\ProjectExporterService;
use App\Service\Project\ResearchProjectManager;
use App\Service\Project\SubProjectManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ResearchProjectViewController extends AbstractController
{
    public function __construct(
        private ResearchProjectManager $rpManager,
        private SubProjectManager $spManager,
        private ProjectExporterService $exporterService,
        private BibliographicReferenceManager $bibReferenceManager,
        private \Doctrine\ORM\EntityManagerInterface $entityManager
    ) {}

    #[Route('/research-project/{id}', name: 'app_research_project_show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $rp = $this->rpManager->getResearchProject($id);
        if (!$rp || $rp->getUser() !== $this->getUser() || $rp->getStatus() === 'deleted') {
            throw $this->createNotFoundException('Projet de recherche introuvable.');
        }

        $subProjects = $this->spManager->getSubProjectsForProject($rp);

        // Calcul des statistiques
        $stats = [
            'total' => count($subProjects),
            'reading' => 0,
            'literature' => 0,
            'writing' => 0,
            'thesis' => 0,
        ];

        foreach ($subProjects as $sp) {
            $type = $sp->getType();
            if ($type === 'literature_review') {
                $type = 'literature';
            }
            if (isset($stats[$type])) {
                $stats[$type]++;
            }
        }

        // Récupérer toutes les références globales de l'utilisateur
        $allReferences = $this->bibReferenceManager->getReferencesForUser($this->getUser());
        
        // Récupérer uniquement les références associées à ce projet
        $projectReferences = $this->bibReferenceManager->getReferencesForProject($rp);

        return $this->render('project/research_project_show.html.twig', [
            'research_project' => $rp,
            'sub_projects' => $subProjects,
            'stats' => $stats,
            'all_references' => $allReferences,
            'project_references' => $projectReferences,
        ]);
    }

    #[Route('/research-project/{id}/toggle-status', name: 'app_research_project_toggle_status', methods: ['POST'])]
    public function toggleStatus(int $id): Response
    {
        $rp = $this->rpManager->getResearchProject($id);
        if (!$rp || $rp->getUser() !== $this->getUser() || $rp->getStatus() === 'deleted') {
            throw $this->createNotFoundException('Projet de recherche introuvable.');
        }

        $newStatus = $rp->getStatus() === 'active' ? 'archived' : 'active';
        $this->rpManager->updateResearchProject($rp, ['status' => $newStatus]);

        $this->addFlash('success', sprintf('Projet de recherche %s avec succès.', $newStatus === 'active' ? 'activé' : 'archivé'));

        return $this->redirectToRoute('app_research_project_show', ['id' => $rp->getId()]);
    }

    #[Route('/research-project/{id}/sub-projects/create', name: 'app_research_project_subproject_create', methods: ['POST'])]
    public function createSubProject(int $id, Request $request): Response
    {
        $rp = $this->rpManager->getResearchProject($id);
        if (!$rp || $rp->getUser() !== $this->getUser() || $rp->getStatus() === 'deleted') {
            throw $this->createNotFoundException('Projet de recherche introuvable.');
        }

        $name = $request->request->get('name');
        $type = $request->request->get('type');

        if (empty($name) || empty($type)) {
            $this->addFlash('error', 'Le nom et le type du sous-projet sont requis.');
            return $this->redirectToRoute('app_research_project_show', ['id' => $rp->getId()]);
        }

        $subProject = $this->spManager->createForUser($this->getUser(), $type, $name, $rp);

        $this->addFlash('success', 'Sous-projet créé avec succès.');

        return $this->redirectToRoute('app_subproject_show', ['id' => $subProject->getId()]);
    }

    #[Route('/sub-project/{id}/edit', name: 'app_subproject_edit', methods: ['POST'])]
    public function editSubProject(int $id, Request $request): Response
    {
        $subProject = $this->entityManagerFind(SubProject::class, $id);
        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Sous-projet introuvable.');
        }

        $name = $request->request->get('name');
        if (empty($name)) {
            $this->addFlash('error', 'Le nom du sous-projet ne peut pas être vide.');
        } else {
            $this->spManager->updateSubProject($subProject, ['name' => $name]);
            $this->addFlash('success', 'Sous-projet renommé avec succès.');
        }

        $rp = $subProject->getResearchProject();
        if ($rp) {
            return $this->redirectToRoute('app_research_project_show', ['id' => $rp->getId()]);
        }

        return $this->redirectToRoute('app_hub');
    }

    #[Route('/sub-project/{id}/toggle-status', name: 'app_subproject_toggle_status', methods: ['POST'])]
    public function toggleSubProjectStatus(int $id): Response
    {
        $subProject = $this->entityManagerFind(SubProject::class, $id);
        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Sous-projet introuvable.');
        }

        $newStatus = $subProject->getStatus() === 'active' ? 'archived' : 'active';
        $this->spManager->updateSubProject($subProject, ['status' => $newStatus]);

        $this->addFlash('success', sprintf('Sous-projet %s avec succès.', $newStatus === 'active' ? 'activé' : 'archivé'));

        $rp = $subProject->getResearchProject();
        if ($rp) {
            return $this->redirectToRoute('app_research_project_show', ['id' => $rp->getId()]);
        }

        return $this->redirectToRoute('app_hub');
    }

    #[Route('/sub-project/{id}/delete', name: 'app_subproject_delete', methods: ['POST'])]
    public function deleteSubProject(int $id): Response
    {
        $subProject = $this->entityManagerFind(SubProject::class, $id);
        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Sous-projet introuvable.');
        }

        $rp = $subProject->getResearchProject();
        $this->spManager->deleteSubProject($subProject);

        $this->addFlash('success', 'Sous-projet supprimé avec succès.');

        if ($rp) {
            return $this->redirectToRoute('app_research_project_show', ['id' => $rp->getId()]);
        }

        return $this->redirectToRoute('app_hub');
    }

    #[Route('/research-project/{id}/export/zip', name: 'app_research_project_export_zip', methods: ['GET'])]
    public function exportZip(int $id): Response
    {
        $rp = $this->rpManager->getResearchProject($id);
        if (!$rp || $rp->getUser() !== $this->getUser() || $rp->getStatus() === 'deleted') {
            throw $this->createNotFoundException('Projet de recherche introuvable.');
        }

        $zipPath = $this->exporterService->exportResearchProjectToZip($rp);

        $response = new BinaryFileResponse($zipPath);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('%s_project.zip', $this->exporterService->slugify($rp->getTitle()))
        );

        return $response;
    }

    private function entityManagerFind(string $className, int $id): ?object
    {
        return $this->entityManager->find($className, $id);
    }
}
