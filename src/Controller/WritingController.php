<?php

namespace App\Controller;

use App\Service\Project\ProjectManager;
use App\Service\WritingService;
use App\Service\File\FileStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Attribute\RateLimiter;

#[Route('/api/writing')]
#[IsGranted('ROLE_USER')]
class WritingController extends AbstractController
{
    public function __construct(
        private WritingService $writingService,
        private ProjectManager $projectManager,
        private FileStorageService $fileStorageService,
        private \App\Service\File\TextExtractorService $textExtractorService,
    ) {
    }

    /**
     * POST /api/writing/export-latex
     * Body JSON: { "content": "string", "filename": "string" }
     *
     * Permet d'exporter le contenu de l'éditeur en fichier LaTeX téléchargeable.
     */
    #[Route('/export-latex', name: 'api_writing_export_latex', methods: ['POST'])]
    public function exportLatex(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $content = $data['content'] ?? '';
        $filename = $data['filename'] ?? 'document.tex';

        if (!str_ends_with($filename, '.tex')) {
            $filename .= '.tex';
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/x-tex');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * POST /api/writing/check
     * Body JSON: { "text": "string", "project_id": int }
     * OU Multipart Form: file, project_id
     *
     * Analyse l'originalité d'un texte ou d'un fichier téléversé.
     */
    #[Route('/check', name: 'api_writing_check', methods: ['POST'])]
    #[Route('/originality', name: 'api_writing_originality', methods: ['POST'])]
    #[RateLimiter('api_ia')]
    public function checkOriginality(Request $request): JsonResponse
    {
        $text = null;
        $projectId = null;
        $uploadedFile = $request->files->get('file');

        if (str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
            $data = json_decode($request->getContent(), true);
            $text = $data['text'] ?? null;
            $projectId = $data['project_id'] ?? null;
        } else {
            $text = $request->request->get('text');
            $projectId = $request->request->get('project_id');
        }

        if (empty($projectId)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Le champ "project_id" est requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $projectId);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        if ($uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            try {
                $document = $this->fileStorageService->upload($uploadedFile, $project, $this->getUser());
                $text = $this->extractText($document->getStoredPath(), $document->getMimeType(), $document->getFilename());
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'error'   => ['code' => 400, 'message' => $e->getMessage()]
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        if (empty($text)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Un texte ou un fichier à analyser est requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($text) < 100) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 422, 'message' => 'Le texte doit contenir au moins 100 caractères.']
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->writingService->checkOriginality($text, $project);

            return $this->json([
                'success' => true,
                'data'    => [
                    'originality_score' => $result['originality_score'],
                    'level'             => $result['level'],
                    'similar_passages'  => $result['similar_passages'],
                    'recommendations'   => $result['recommendations'],
                    'interaction_id'    => $result['interaction'] ? $result['interaction']->getId() : null,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 503, 'message' => 'Service IA temporairement indisponible.']
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * POST /api/writing/suggest-journal
     * Body JSON: { "text": "string", "project_id": int, "limit": 3 }
     * OU Multipart Form: file, project_id, limit
     *
     * Suggère des revues scientifiques adaptées au texte ou au fichier téléversé.
     */
    #[Route('/suggest-journal', name: 'api_writing_suggest_journal', methods: ['POST'])]
    #[Route('/journals', name: 'api_writing_journals', methods: ['POST'])]
    #[RateLimiter('api_ia')]
    public function suggestJournal(Request $request): JsonResponse
    {
        $text = null;
        $projectId = null;
        $limit = 3;
        $uploadedFile = $request->files->get('file');

        if (str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
            $data = json_decode($request->getContent(), true);
            $text = $data['text'] ?? null;
            $projectId = $data['project_id'] ?? null;
            $limit = isset($data['limit']) ? (int) $data['limit'] : 3;
        } else {
            $text = $request->request->get('text');
            $projectId = $request->request->get('project_id');
            $limit = $request->request->get('limit') ? (int) $request->request->get('limit') : 3;
        }

        if (empty($projectId)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Le champ "project_id" est requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $projectId);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        if ($uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            try {
                $document = $this->fileStorageService->upload($uploadedFile, $project, $this->getUser());
                $text = $this->extractText($document->getStoredPath(), $document->getMimeType(), $document->getFilename());
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'error'   => ['code' => 400, 'message' => $e->getMessage()]
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        if (empty($text)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Un texte ou un fichier est requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $limit = max(1, min($limit, 10));

        try {
            $result = $this->writingService->suggestJournal($text, $project, $limit);

            return $this->json([
                'success' => true,
                'data'    => [
                    'journals'       => $result['journals'],
                    'interaction_id' => $result['interaction'] ? $result['interaction']->getId() : null,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 503, 'message' => 'Service IA temporairement indisponible.']
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * Extrait le contenu textuel brut d'un document PDF ou LaTeX.
     */
    private function extractText(string $path, string $mimeType, string $filename): string
    {
        return $this->textExtractorService->extractText($path, $mimeType, $filename);
    }
}
