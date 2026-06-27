<?php

namespace App\Controller;

use App\Entity\ResearchProject;
use App\Entity\SubProject;
use App\Service\Project\ResearchProjectManager;
use App\Service\Project\SubProjectManager;
use App\Service\Project\ProjectSwitcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class SubProjectViewController extends AbstractController
{
    public function __construct(
        private SubProjectManager $spManager,
        private ResearchProjectManager $rpManager,
        private ProjectSwitcher $projectSwitcher,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/sub-project/{id}', name: 'app_subproject_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $subProject = $this->entityManager->find(SubProject::class, $id);
        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Sous-projet introuvable.');
        }

        // Récupérer le projet hérité compagnon pour rediriger vers l'interface correspondante
        $legacyProject = $subProject->getProjects()->first();

        // Si aucun projet compagnon n'existe (par exemple insertion manuelle), on le génère
        if (!$legacyProject) {
            $legacyProject = new \App\Entity\Project();
            $legacyProject->setUser($this->getUser());
            
            $legacyType = $subProject->getType();
            if ($legacyType === 'literature') {
                $legacyType = 'literature_review';
            }
            $legacyProject->setType($legacyType);
            $legacyProject->setName($subProject->getName());
            $legacyProject->setStatus('active');
            $legacyProject->setCreatedAt(new \DateTime());
            $legacyProject->setResearchProject($subProject->getResearchProject());
            $legacyProject->setSubProject($subProject);

            $this->entityManager->persist($legacyProject);
            $this->entityManager->flush();
        }

        // Redirection vers les routes d'activité héritées
        $type = $subProject->getType();
        $targetRoute = match ($type) {
            'reading' => 'app_project_reading',
            'literature' => 'app_project_literature',
            'writing' => 'app_project_writing',
            'thesis' => 'app_project_thesis',
            default => 'app_project_show'
        };

        return $this->redirectToRoute($targetRoute, ['id' => $legacyProject->getId()]);
    }

    #[Route('/research-project/{projectId}/sub-projects/{type}', name: 'app_research_project_subprojects', methods: ['GET'])]
    #[Route('/sub-projects/type/{type}', name: 'app_orphan_subprojects', methods: ['GET'])]
    public function list(string $type, Request $request, ?int $projectId = null): Response
    {
        // Validation du type
        if (!in_array($type, ['reading', 'literature', 'writing', 'thesis'])) {
            throw $this->createNotFoundException('Type de sous-projet inconnu.');
        }

        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 20);

        $researchProject = null;
        if ($projectId !== null) {
            $researchProject = $this->rpManager->getResearchProject($projectId);
            if (!$researchProject || $researchProject->getUser() !== $this->getUser()) {
                throw $this->createNotFoundException('Projet de recherche introuvable.');
            }
            $pagination = $this->spManager->getSubProjectsForProject($researchProject, $type, $page, $limit);
        } else {
            // Pas de projet de recherche explicite dans l'URL. On regarde si un projet est actif en session.
            $activeRp = $this->projectSwitcher->getActiveProject($this->getUser());
            if ($activeRp) {
                // Redirection transparente vers l'URL spécifique pour maintenir la cohérence de navigation
                return $this->redirectToRoute('app_research_project_subprojects', [
                    'projectId' => $activeRp->getId(),
                    'type' => $type,
                    'page' => $page,
                    'limit' => $limit
                ]);
            }
            
            // Sinon, afficher les sous-projets orphelins
            $pagination = $this->spManager->getOrphanSubProjectsForUser($this->getUser(), $type, $page, $limit);
        }

        if ($request->isXmlHttpRequest() || str_contains($request->headers->get('Accept', ''), 'application/json')) {
            return $this->json([
                'success' => true,
                'data' => [
                    'items' => $pagination['items'],
                    'pagination' => [
                        'total' => $pagination['total'],
                        'page' => $pagination['page'],
                        'limit' => $pagination['limit'],
                        'pages' => $pagination['pages']
                    ]
                ]
            ], Response::HTTP_OK, [], ['groups' => 'project:read']);
        }

        $titles = [
            'reading' => 'Projets de lecture',
            'literature' => 'Projets de synthèse',
            'writing' => "Projets d'écriture",
            'thesis' => 'Thèses et Mémoires',
        ];

        $subtitles = [
            'reading' => 'Consultez, annotez et dialoguez avec vos documents PDF de recherche.',
            'literature' => 'Organisez vos synthèses et générez vos états de l\'art basés sur des articles scientifiques.',
            'writing' => 'Rédigez vos manuscrits scientifiques, vérifiez l\'originalité et exportez en LaTeX.',
            'thesis' => 'Structurez votre mémoire ou thèse et suivez la cohérence globale de vos chapitres.',
        ];

        return $this->render('sub_project/list.html.twig', [
            'type' => $type,
            'title' => $titles[$type],
            'subtitle' => $subtitles[$type],
            'sub_projects' => $pagination['items'],
            'pagination' => $pagination,
            'research_project' => $researchProject
        ]);
    }

    #[Route('/sub-project/new', name: 'app_subproject_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $type = $request->query->get('type', 'reading');
        $researchProjects = $this->rpManager->getUserResearchProjects($this->getUser());
        $activeRp = $this->projectSwitcher->getActiveProject($this->getUser());

        return $this->render('sub_project/new.html.twig', [
            'type' => $type,
            'research_projects' => $researchProjects,
            'active_rp' => $activeRp
        ]);
    }

    #[Route('/sub-project/create', name: 'app_subproject_create_post', methods: ['POST'])]
    public function createSubProject(Request $request): Response
    {
        $name = $request->request->get('name');
        $type = $request->request->get('type');
        $rpId = $request->request->get('research_project_id');
        
        if (empty($name) || empty($type)) {
            $this->addFlash('error', 'Le nom et le type du sous-projet sont requis.');
            return $this->redirectToRoute('app_hub');
        }

        $rp = null;
        if (!empty($rpId)) {
            $rp = $this->rpManager->getResearchProject((int)$rpId);
            if (!$rp || $rp->getUser() !== $this->getUser()) {
                throw $this->createNotFoundException('Projet de recherche introuvable.');
            }
        } else {
            // Fallback sur le projet actif s'il existe
            $rp = $this->projectSwitcher->getActiveProject($this->getUser());
        }

        $subProject = $this->spManager->createForUser($this->getUser(), $type, $name, $rp);

        $this->addFlash('success', 'Sous-projet créé avec succès.');

        return $this->redirectToRoute('app_subproject_show', ['id' => $subProject->getId()]);
    }
}
