<?php

namespace App\Controller;

use App\Entity\BibliographicReference;
use App\Entity\ResearchProject;
use App\Service\Bibliography\BibliographicReferenceManager;
use App\Service\Bibliography\BibtexImporter;
use App\Service\Bibliography\DoiResolver;
use App\Service\Bibliography\BibliographyExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class BibliographyDashboardController extends AbstractController
{
    public function __construct(
        private BibliographicReferenceManager $manager,
        private BibtexImporter $importer,
        private DoiResolver $doiResolver,
        private EntityManagerInterface $entityManager,
        private BibliographyExporter $exporter
    ) {
    }

    #[Route('/bibliography', name: 'app_bibliography_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $query = $request->query->get('q', '');
        $typeFilter = $request->query->get('type', '');

        $filters = [];
        if (!empty($typeFilter)) {
            $filters['entryType'] = $typeFilter;
        }

        // Récupérer et filtrer les références
        $references = $this->manager->searchReferences($user, $query, $filters);

        // Obtenir tous les types d'entrées disponibles chez cet utilisateur pour filtrage
        $allUserRefs = $this->manager->getReferencesForUser($user);
        $entryTypes = array_unique(array_map(function (BibliographicReference $ref) {
            return $ref->getEntryType();
        }, $allUserRefs));

        return $this->render('bibliography/dashboard.html.twig', [
            'references' => $references,
            'entry_types' => $entryTypes,
            'current_query' => $query,
            'current_type' => $typeFilter,
        ]);
    }

    #[Route('/bibliography/add', name: 'app_bibliography_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $user = $this->getUser();
        $data = [
            'citeKey' => $request->request->get('citeKey'),
            'entryType' => $request->request->get('entryType', 'misc'),
            'title' => $request->request->get('title'),
            'authors' => $request->request->get('authors'),
            'year' => $request->request->get('year'),
            'journal' => $request->request->get('journal'),
            'doi' => $request->request->get('doi'),
            'source' => $request->request->get('source', 'manual'),
        ];

        $projectId = $request->request->get('project_id');

        try {
            $ref = $this->manager->createReference($user, $data);
            if ($projectId) {
                $project = $this->entityManager->getRepository(ResearchProject::class)->find($projectId);
                if ($project && $project->getUser() === $user) {
                    $this->manager->addToProject($ref, $project);
                }
            }
            $this->addFlash('success', 'La référence a été ajoutée avec succès.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'ajout de la référence : ' . $e->getMessage());
        }

        return $this->redirectToReferer($request, '/bibliography');
    }

    #[Route('/bibliography/import', name: 'app_bibliography_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $user = $this->getUser();
        $bibContent = '';

        $file = $request->files->get('bib_file');
        if ($file) {
            $bibContent = file_get_contents($file->getPathname());
        } else {
            $bibContent = $request->request->get('bib_content', '');
        }

        if (empty(trim($bibContent))) {
            $this->addFlash('error', 'Veuillez fournir un fichier BibTeX ou coller du texte BibTeX valide.');
            return $this->redirectToReferer($request, '/bibliography');
        }

        try {
            $stats = $this->importer->import($user, $bibContent);
            $this->addFlash('success', sprintf('%d référence(s) importée(s) avec succès sur %d trouvée(s).', $stats['imported'], $stats['total']));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'importation BibTeX : ' . $e->getMessage());
        }

        return $this->redirectToReferer($request, '/bibliography');
    }

    #[Route('/bibliography/resolve-doi', name: 'app_bibliography_resolve_doi', methods: ['GET'])]
    public function resolveDoi(Request $request): JsonResponse
    {
        $doi = $request->query->get('doi', '');
        if (empty($doi)) {
            return $this->json(['success' => false, 'error' => 'DOI manquant.'], Response::HTTP_BAD_REQUEST);
        }

        $metadata = $this->doiResolver->resolve($doi);
        if (!$metadata) {
            return $this->json(['success' => false, 'error' => 'Impossible de résoudre ce DOI via Crossref.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => $metadata
        ]);
    }

    #[Route('/bibliography/delete/{id}', name: 'app_bibliography_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $ref = $this->entityManager->getRepository(BibliographicReference::class)->find($id);
        if (!$ref || $ref->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Référence introuvable.');
        }

        try {
            $this->manager->deleteReference($ref);
            $this->addFlash('success', 'La référence a été supprimée.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression.');
        }

        return $this->redirectToReferer($request, '/bibliography');
    }

    #[Route('/bibliography/project/{projectId}/add', name: 'app_bibliography_project_add', methods: ['POST'])]
    public function addToProject(int $projectId, Request $request): Response
    {
        $project = $this->entityManager->getRepository(ResearchProject::class)->find($projectId);
        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Projet introuvable.');
        }

        $referenceIds = $request->request->all('reference_ids');
        if (empty($referenceIds)) {
            $singleId = $request->request->get('reference_id');
            if ($singleId) {
                $referenceIds = [$singleId];
            }
        }

        if (empty($referenceIds)) {
            $this->addFlash('error', 'Aucune référence sélectionnée.');
            return $this->redirectToRoute('app_research_project_show', ['id' => $projectId]);
        }

        $addedCount = 0;
        foreach ($referenceIds as $refId) {
            $ref = $this->entityManager->getRepository(BibliographicReference::class)->find($refId);
            if ($ref && $ref->getUser() === $this->getUser()) {
                $this->manager->addToProject($ref, $project);
                $addedCount++;
            }
        }

        if ($addedCount > 0) {
            $this->addFlash('success', sprintf('%d référence(s) associée(s) au projet.', $addedCount));
        }

        return $this->redirectToRoute('app_research_project_show', ['id' => $projectId]);
    }

    #[Route('/bibliography/project/{projectId}/remove/{referenceId}', name: 'app_bibliography_project_remove', methods: ['POST'])]
    public function removeFromProject(int $projectId, int $referenceId, Request $request): Response
    {
        $project = $this->entityManager->getRepository(ResearchProject::class)->find($projectId);
        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Projet introuvable.');
        }

        $ref = $this->entityManager->getRepository(BibliographicReference::class)->find($referenceId);
        if (!$ref || $ref->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Référence introuvable.');
        }

        try {
            $this->manager->removeFromProject($ref, $project);
            $this->addFlash('success', 'La référence a été dissociée du projet.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur de dissociation.');
        }

        return $this->redirectToRoute('app_research_project_show', ['id' => $projectId]);
    }

    #[Route('/api/user/bibliographic-references', name: 'api_user_bibliographic_references', methods: ['GET'])]
    public function listUserReferences(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $query = $request->query->get('q', '');
        
        $references = $this->manager->searchReferences($user, $query);
        
        $data = [];
        foreach ($references as $ref) {
            $data[] = [
                'id' => $ref->getId(),
                'citeKey' => $ref->getCiteKey(),
                'entryType' => $ref->getEntryType(),
                'title' => $ref->getTitle(),
                'authors' => $ref->getAuthors(),
                'year' => $ref->getYear(),
                'journal' => $ref->getJournal(),
                'doi' => $ref->getDoi(),
            ];
        }

        return $this->json([
            'success' => true,
            'data' => [
                'entries' => $data
            ]
        ]);
    }

    #[Route('/api/research-project/{projectId}/bibliography/add', name: 'api_bibliography_project_add_api', methods: ['POST'])]
    public function addProjectReferencesApi(int $projectId, Request $request): JsonResponse
    {
        $project = $this->entityManager->getRepository(ResearchProject::class)->find($projectId);
        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => 'Projet introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $referenceIds = $data['reference_ids'] ?? [];

        if (empty($referenceIds)) {
            return $this->json(['success' => false, 'error' => 'Aucune référence sélectionnée.'], Response::HTTP_BAD_REQUEST);
        }

        $addedCount = 0;
        foreach ($referenceIds as $refId) {
            $ref = $this->entityManager->getRepository(BibliographicReference::class)->find($refId);
            if ($ref && $ref->getUser() === $this->getUser()) {
                $this->manager->addToProject($ref, $project);
                $addedCount++;
            }
        }

        return $this->json([
            'success' => true,
            'message' => sprintf('%d référence(s) associée(s) au projet.', $addedCount)
        ]);
    }

    #[Route('/api/user/bibliographic-references/render', name: 'api_user_bibliographic_references_render', methods: ['GET'])]
    public function renderBibliographyHtml(Request $request): JsonResponse
    {
        $keysString = $request->query->get('keys', '');
        if (empty($keysString)) {
            return $this->json([
                'success' => true,
                'html' => ''
            ]);
        }

        $keys = array_filter(array_map('trim', explode(',', $keysString)));
        $references = $this->exporter->getReferencesByKeys($this->getUser(), $keys);
        
        $html = $this->exporter->generateHtml($references);

        return $this->json([
            'success' => true,
            'html' => $html
        ]);
    }

    #[Route('/bibliography/export', name: 'app_bibliography_export', methods: ['GET'])]
    public function exportGlobal(Request $request): Response
    {
        $user = $this->getUser();
        $references = $this->manager->getReferencesForUser($user);
        
        $bibtexContent = $this->exporter->exportToBibtex($references);
        
        $response = new Response($bibtexContent);
        $response->headers->set('Content-Type', 'application/x-bibtex');
        $response->headers->set('Content-Disposition', 'attachment; filename="ma_bibliotheque.bib"');
        
        return $response;
    }

    #[Route('/bibliography/project/{projectId}/export', name: 'app_bibliography_project_export', methods: ['GET'])]
    public function exportProject(int $projectId): Response
    {
        $project = $this->entityManager->getRepository(ResearchProject::class)->find($projectId);
        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Projet introuvable.');
        }

        $references = $this->manager->getReferencesForProject($project);
        
        $bibtexContent = $this->exporter->exportToBibtex($references);
        
        $response = new Response($bibtexContent);
        $response->headers->set('Content-Type', 'application/x-bibtex');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="bibliographie_projet_%d.bib"', $projectId));
        
        return $response;
    }

    /**
     * Redirige vers la page précédente (HTTP referer) ou vers une fallback par défaut.
     */
    private function redirectToReferer(Request $request, string $defaultUrl): Response
    {
        $referer = $request->headers->get('referer');
        if (!empty($referer)) {
            return $this->redirect($referer);
        }
        return $this->redirect($defaultUrl);
    }
}
