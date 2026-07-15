<?php

namespace App\Controller\Api;

use App\Service\Project\ProjectManager;
use App\Service\Converter\LatexConverter;
use App\Service\Bibliography\BibliographyManager;
use App\Repository\SubProjectRepository;
use App\Repository\BibliographyEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Attribute\RateLimiter;

#[Route('/api')]
#[IsGranted('ROLE_USER')]
class EditorController extends AbstractController
{
    public function __construct(
        private ProjectManager $projectManager,
        private LatexConverter $latexConverter,
        private EntityManagerInterface $entityManager,
        private \App\Service\Editor\AIAssistantService $aiAssistantService,
        private \App\Service\Editor\EditorHistoryManager $editorHistoryManager,
        private BibliographyManager $bibliographyManager,
        private SubProjectRepository $subProjectRepository,
        private BibliographyEntryRepository $bibliographyEntryRepository,
    ) {
    }

    /**
     * POST /api/projects/{id}/content
     * Body JSON: { "content": "string", "mode": "string" }
     *
     * Enregistre automatiquement le contenu et le mode de l'éditeur dans les métadonnées du projet.
     */
    #[Route('/projects/{id}/content', name: 'api_project_save_content', methods: ['POST'])]
    public function saveContent(int $id, Request $request): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $contentWysiwyg = $data['content_wysiwyg'] ?? null;
        $contentLatex = $data['content_latex'] ?? null;
        // Rétrocompatibilité au cas où un ancien payload sans séparation est envoyé
        $contentLegacy = $data['content'] ?? null;
        $mode = $data['mode'] ?? 'wysiwyg';

        $metadata = $project->getMetadata() ?? [];
        
        if ($contentWysiwyg !== null) {
            $metadata['writing_content_wysiwyg'] = $contentWysiwyg;
        } elseif ($contentLegacy !== null && $mode === 'wysiwyg') {
            $metadata['writing_content_wysiwyg'] = $contentLegacy;
        }

        if ($contentLatex !== null) {
            $metadata['writing_content_latex'] = $contentLatex;
        } elseif ($contentLegacy !== null && $mode === 'latex') {
            $metadata['writing_content_latex'] = $contentLegacy;
        }

        // Toujours garder 'writing_content' synchronisé avec l'éditeur actif pour compatibilité export/preview
        $metadata['writing_content'] = ($mode === 'latex') 
            ? ($metadata['writing_content_latex'] ?? '') 
            : ($metadata['writing_content_wysiwyg'] ?? '');
            
        $metadata['writing_mode'] = $mode;

        $project->setMetadata($metadata);
        $project->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'project_id' => $project->getId(),
                'updated_at' => $project->getUpdatedAt()->format('c')
            ]
        ]);
    }

    /**
     * GET /api/projects/{id}/content
     *
     * Récupère le contenu et le mode de rédaction précédemment sauvegardés.
     */
    #[Route('/projects/{id}/content', name: 'api_project_get_content', methods: ['GET'])]
    public function getContent(int $id): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $metadata = $project->getMetadata() ?? [];
        $contentWysiwyg = $metadata['writing_content_wysiwyg'] ?? $metadata['writing_content'] ?? '';
        $contentLatex = $metadata['writing_content_latex'] ?? $metadata['writing_content'] ?? '';
        $mode = $metadata['writing_mode'] ?? 'wysiwyg';

        return $this->json([
            'success' => true,
            'data' => [
                'content_wysiwyg' => $contentWysiwyg,
                'content_latex' => $contentLatex,
                'mode' => $mode
            ]
        ]);
    }

    /**
     * POST /api/export/latex
     * Body JSON: { "content": "string", "filename": "string|null" }
     *
     * Convertit le contenu en LaTeX s'il est au format HTML/WYSIWYG,
     * puis sert le fichier .tex au téléchargement.
     */
    #[Route('/export/latex', name: 'api_editor_export_latex', methods: ['POST'])]
    public function exportLatex(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $content = $data['content'] ?? '';
        $filename = $data['filename'] ?? 'document.tex';

        if (!str_ends_with($filename, '.tex')) {
            $filename .= '.tex';
        }

        // Si le contenu contient des balises HTML, on le convertit proprement en LaTeX
        if (str_contains($content, '<p>') || str_contains($content, '<h1>') || str_contains($content, '<strong>')) {
            $content = $this->latexConverter->htmlToLatex($content);
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/x-tex');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * POST /api/convert/latex-to-html
     * Body JSON: { "content": "string" }
     *
     * Convertit du LaTeX en HTML léger pour la prévisualisation WYSIWYG.
     */
    #[Route('/convert/latex-to-html', name: 'api_editor_convert_latex_to_html', methods: ['POST'])]
    public function convertLatexToHtml(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $latex = $data['content'] ?? '';

        $html = $this->latexConverter->latexToHtml($latex);

        return $this->json([
            'success' => true,
            'data' => [
                'html' => $html
            ]
        ]);
    }

    /**
     * POST /api/projects/{id}/editor-ai/execute
     * Body JSON: { "action": "string", "text": "string", "options": {} }
     */
    #[Route('/projects/{id}/editor-ai/execute', name: 'api_editor_ai_execute', methods: ['POST'])]
    #[RateLimiter('api_ia')]
    public function executeAiAction(int $id, Request $request): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $action = $data['action'] ?? null;
        $text = $data['text'] ?? null;
        $options = $data['options'] ?? [];

        if (!$action || $text === null) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 400, 'message' => 'Les champs "action" et "text" sont requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $res = $this->aiAssistantService->execute($action, $text, $project->getSubProject(), $this->getUser(), $options);
            return $this->json([
                'success' => true,
                'data' => $res
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 500, 'message' => $e->getMessage()]
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/projects/{id}/editor-ai/stream
     * Body JSON: { "action": "string", "text": "string", "options": {} }
     *
     * Retourne un flux SSE (Content-Type: text/event-stream).
     */
    #[Route('/projects/{id}/editor-ai/stream', name: 'api_editor_ai_stream', methods: ['POST'])]
    #[RateLimiter('api_ia')]
    public function streamAiAction(int $id, Request $request): Response
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return new Response('data: ' . json_encode(['error' => 'Projet non trouvé.']) . "\n\n", Response::HTTP_NOT_FOUND, [
                'Content-Type' => 'text/event-stream'
            ]);
        }

        $data = json_decode($request->getContent(), true);
        $action = $data['action'] ?? null;
        $text = $data['text'] ?? null;
        $options = $data['options'] ?? [];

        if (!$action || $text === null) {
            return new Response('data: ' . json_encode(['error' => 'Champs manquants.']) . "\n\n", Response::HTTP_BAD_REQUEST, [
                'Content-Type' => 'text/event-stream'
            ]);
        }

        $subProject = $project->getSubProject();
        $user = $this->getUser();

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($action, $text, $subProject, $user, $options) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            try {
                $interactionId = $this->aiAssistantService->stream(
                    $action,
                    $text,
                    $subProject,
                    $user,
                    function (string $chunk) {
                        echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                        flush();
                    },
                    $options
                );

                echo "data: " . json_encode(['interaction_id' => $interactionId]) . "\n\n";
            } catch (\Throwable $e) {
                echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
            }

            echo "data: [DONE]\n\n";
            flush();
        }, Response::HTTP_OK, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);

        return $response;
    }

    /**
     * GET /api/projects/{id}/editor-ai/history
     */
    #[Route('/projects/{id}/editor-ai/history', name: 'api_editor_ai_history', methods: ['GET'])]
    public function getAiHistory(int $id): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $subProject = $project->getSubProject();
        if (!$subProject) {
            return $this->json([
                'success' => true,
                'data' => []
            ]);
        }

        $history = $this->editorHistoryManager->getHistory($subProject);

        $formatted = array_map(function (\App\Entity\EditorInteraction $item) {
            return [
                'id' => $item->getId(),
                'action' => $item->getAction(),
                'selectedText' => $item->getSelectedText(),
                'suggestion' => $item->getSuggestion(),
                'accepted' => $item->isAccepted(),
                'createdAt' => $item->getCreatedAt()->format('c'),
            ];
        }, $history);

        return $this->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    /**
     * POST /api/editor-ai/interaction/{interactionId}/status
     */
    #[Route('/editor-ai/interaction/{interactionId}/status', name: 'api_editor_ai_status', methods: ['POST'])]
    public function updateAiStatus(int $interactionId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $accepted = $data['accepted'] ?? null;

        if ($accepted === null) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 400, 'message' => 'Le champ "accepted" est requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $interaction = $this->editorHistoryManager->updateStatus($interactionId, (bool) $accepted, $this->getUser());

        if (!$interaction) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Interaction non trouvée.']
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => [
                'id' => $interaction->getId(),
                'accepted' => $interaction->isAccepted()
            ]
        ]);
    }

    /**
     * POST /api/projects/{id}/snapshots
     * Body JSON: { "name": "string", "content_wysiwyg": "string", "content_latex": "string", "mode": "string" }
     */
    #[Route('/projects/{id}/snapshots', name: 'api_project_save_snapshot', methods: ['POST'])]
    public function saveSnapshot(int $id, Request $request): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');
        $contentWysiwyg = $data['content_wysiwyg'] ?? '';
        $contentLatex = $data['content_latex'] ?? '';
        $mode = $data['mode'] ?? 'wysiwyg';

        $metadata = $project->getMetadata() ?? [];
        $snapshots = $metadata['editor_snapshots'] ?? [];

        $newSnapshot = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name ?: 'Version du ' . (new \DateTime())->format('d/m/Y H:i'),
            'content_wysiwyg' => $contentWysiwyg,
            'content_latex' => $contentLatex,
            'mode' => $mode,
            'created_at' => (new \DateTime())->format('c')
        ];

        $snapshots[] = $newSnapshot;
        $metadata['editor_snapshots'] = $snapshots;
        $project->setMetadata($metadata);

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => $newSnapshot
        ]);
    }

    /**
     * GET /api/projects/{id}/snapshots
     */
    #[Route('/projects/{id}/snapshots', name: 'api_project_get_snapshots', methods: ['GET'])]
    public function getSnapshots(int $id): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $metadata = $project->getMetadata() ?? [];
        $snapshots = $metadata['editor_snapshots'] ?? [];

        usort($snapshots, function ($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        return $this->json([
            'success' => true,
            'data' => $snapshots
        ]);
    }

    /**
     * DELETE /api/projects/{id}/snapshots/{snapshotId}
     */
    #[Route('/projects/{id}/snapshots/{snapshotId}', name: 'api_project_delete_snapshot', methods: ['DELETE'])]
    public function deleteSnapshot(int $id, string $snapshotId): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $metadata = $project->getMetadata() ?? [];
        $snapshots = $metadata['editor_snapshots'] ?? [];

        $filteredSnapshots = array_filter($snapshots, function ($item) use ($snapshotId) {
            return $item['id'] !== $snapshotId;
        });

        $metadata['editor_snapshots'] = array_values($filteredSnapshots);
        $project->setMetadata($metadata);

        $this->entityManager->flush();

        return $this->json([
            'success' => true
        ]);
    }

    /**
     * GET /api/projects/{id}/citations
     */
    #[Route('/projects/{id}/citations', name: 'api_project_get_citations', methods: ['GET'])]
    public function getCitations(int $id): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $documents = $this->entityManager->getRepository(\App\Entity\Document::class)->findBy(['project' => $project]);

        $data = [];
        foreach ($documents as $doc) {
            $data[] = [
                'id' => $doc->getId(),
                'key' => 'ref_' . $doc->getId(),
                'filename' => $doc->getFilename(),
                'title' => $doc->getFilename(),
                'created_at' => $doc->getCreatedAt()?->format('Y')
            ];
        }

        return $this->json([
            'success' => true,
            'data' => $data
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BIBLIOGRAPHIE — Routes dédiées aux références BibTeX par sous-projet
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/sub-projects/{id}/bibliography/import
     *
     * Upload et parse un fichier .bib, importe les références dans le sous-projet.
     * Corps : multipart/form-data avec champ 'bib_file' (fichier .bib)
     * OU JSON : { "bib_content": "...contenu brut..." }
     */
    #[Route('/sub-projects/{id}/bibliography/import', name: 'api_bibliography_import', methods: ['POST'])]
    public function importBibliography(int $id, Request $request): JsonResponse
    {
        $subProject = $this->subProjectRepository->find($id);

        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Sous-projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $bibContent = null;

        // Cas 1 : fichier uploadé
        $uploadedFile = $request->files->get('bib_file');
        if ($uploadedFile) {
            $extension = strtolower($uploadedFile->getClientOriginalExtension());
            if ($extension !== 'bib') {
                return $this->json([
                    'success' => false,
                    'error'   => ['code' => 400, 'message' => 'Le fichier doit être au format .bib']
                ], Response::HTTP_BAD_REQUEST);
            }
            $bibContent = file_get_contents($uploadedFile->getPathname());
        }

        // Cas 2 : contenu JSON brut
        if (!$bibContent) {
            $data = json_decode($request->getContent(), true);
            $bibContent = $data['bib_content'] ?? null;
        }

        if (empty($bibContent)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Aucun contenu BibTeX fourni.']
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->bibliographyManager->importFromBib($subProject, $bibContent);

            return $this->json([
                'success' => true,
                'data'    => [
                    'imported' => $result['imported'],
                    'updated'  => $result['updated'],
                    'total'    => $result['total'],
                    'message'  => sprintf(
                        '%d référence(s) importée(s), %d mise(s) à jour.',
                        $result['imported'],
                        $result['updated']
                    ),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 500, 'message' => 'Erreur lors du parsing BibTeX : ' . $e->getMessage()]
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/sub-projects/{id}/bibliography
     *
     * Retourne la liste complète des références du sous-projet.
     */
    #[Route('/sub-projects/{id}/bibliography', name: 'api_bibliography_list', methods: ['GET'])]
    public function listBibliography(int $id): JsonResponse
    {
        $subProject = $this->subProjectRepository->find($id);

        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Sous-projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $entries = $this->bibliographyManager->getEntries($subProject);

        return $this->json([
            'success' => true,
            'data'    => [
                'entries' => $this->bibliographyManager->toApiArray($entries),
                'count'   => count($entries),
            ],
        ]);
    }

    /**
     * GET /api/sub-projects/{id}/bibliography/search?q=terme
     *
     * Recherche plein texte dans titre, auteurs et citeKey.
     */
    #[Route('/sub-projects/{id}/bibliography/search', name: 'api_bibliography_search', methods: ['GET'])]
    public function searchBibliography(int $id, Request $request): JsonResponse
    {
        $subProject = $this->subProjectRepository->find($id);

        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Sous-projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $query   = $request->query->get('q', '');
        $entries = $this->bibliographyManager->searchEntries($subProject, $query);

        return $this->json([
            'success' => true,
            'data'    => [
                'entries' => $this->bibliographyManager->toApiArray($entries),
                'count'   => count($entries),
                'query'   => $query,
            ],
        ]);
    }

    /**
     * DELETE /api/sub-projects/{id}/bibliography/{entryId}
     *
     * Supprime une référence bibliographique.
     */
    #[Route('/sub-projects/{id}/bibliography/{entryId}', name: 'api_bibliography_delete', methods: ['DELETE'])]
    public function deleteBibliographyEntry(int $id, int $entryId): JsonResponse
    {
        $subProject = $this->subProjectRepository->find($id);

        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Sous-projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $entry = $this->bibliographyEntryRepository->find($entryId);

        if (!$entry || $entry->getSubProject() !== $subProject) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Référence non trouvée.']
            ], Response::HTTP_NOT_FOUND);
        }

        $this->bibliographyManager->deleteEntry($entry);

        return $this->json(['success' => true]);
    }
}

