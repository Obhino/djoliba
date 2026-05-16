<?php

namespace App\Controller;

use App\Service\Project\ProjectManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ProjectViewController extends AbstractController
{
    public function __construct(
        private ProjectManager $projectManager,
        private \App\Service\Project\ProjectExporterService $exporterService
    ) {}

    #[IsGranted('ROLE_USER')]
    #[Route('/project/{id}/export/zip', name: 'app_project_export_zip')]
    public function exportZip(int $id): Response
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Projet non trouvé.');
        }

        try {
            $zipPath = $this->exporterService->exportToZip($project);
            
            return $this->file($zipPath, sprintf('%s_export.zip', $this->exporterService->slugify($project->getName())));
        } catch (\Exception $e) {
            $this->addFlash('error', "L'export a échoué : " . $e->getMessage());
            return $this->redirectToRoute('app_project_show', ['id' => $id]);
        }
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/project/{id}/export/pdf', name: 'app_project_export_pdf')]
    public function exportPdf(int $id): Response
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Projet non trouvé.');
        }

        try {
            $pdfPath = $this->exporterService->exportToPdf($project);
            
            return $this->file($pdfPath, sprintf('%s_export.pdf', $this->exporterService->slugify($project->getName())));
        } catch (\Exception $e) {
            $this->addFlash('error', "L'export PDF a échoué : " . $e->getMessage());
            return $this->redirectToRoute('app_project_show', ['id' => $id]);
        }
    }

    #[Route('/hub', name: 'app_hub')]
    public function hub(): Response
    {
        // On autorise si l'utilisateur est connecté OU si le mode test est actif
        if (!$this->getUser() && !$this->container->get('request_stack')->getCurrentRequest()->getSession()->get('is_test_mode')) {
            return $this->redirectToRoute('app_login');
        }

        $projects = $this->getUser() ? $this->projectManager->getUserProjects($this->getUser()) : [];
        
        return $this->render('project/hub.html.twig', [
            'projects' => $projects,
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        $projects = $this->projectManager->getUserProjects($this->getUser());
        
        return $this->render('project/dashboard.html.twig', [
            'projects' => $projects,
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/project/{id}', name: 'app_project_show')]
    public function show(int $id): Response
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Projet non trouvé.');
        }

        return $this->render('project/show.html.twig', [
            'project' => $project,
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/project/{id}/literature', name: 'app_project_literature')]
    public function literature(int $id): Response
    {
        $project = $this->projectManager->getProject($id);
        return $this->render('project/literature.html.twig', ['project' => $project]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/project/{id}/reading', name: 'app_project_reading')]
    public function reading(int $id): Response
    {
        $project = $this->projectManager->getProject($id);
        return $this->render('project/reading.html.twig', ['project' => $project]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/project/{id}/thesis', name: 'app_project_thesis')]
    public function thesis(int $id): Response
    {
        $project = $this->projectManager->getProject($id);
        return $this->render('project/thesis.html.twig', ['project' => $project]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/project/{id}/writing', name: 'app_project_writing')]
    public function writing(int $id): Response
    {
        $project = $this->projectManager->getProject($id);
        return $this->render('project/writing.html.twig', ['project' => $project]);
    }
}
