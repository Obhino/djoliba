<?php

namespace App\Controller;

use App\Entity\Chapter;
use App\Repository\ChapterRepository;
use App\Service\Project\ProjectManager;
use App\Service\ThesisService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/thesis')]
#[IsGranted('ROLE_USER')]
class ThesisController extends AbstractController
{
    public function __construct(
        private ThesisService     $thesisService,
        private ProjectManager    $projectManager,
        private ChapterRepository $chapterRepository,
    ) {
    }

    /**
     * GET /api/thesis/structure
     * Paramètre de requête : ?project_id=int
     */
    #[Route('/structure', name: 'api_thesis_structure', methods: ['GET'])]
    public function getStructure(Request $request): JsonResponse
    {
        $projectId = $request->query->get('project_id');

        if (!$projectId) {
            return $this->json(['success' => false, 'error' => ['code' => 400, 'message' => 'Le paramètre "project_id" est requis.']], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $projectId);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Projet non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        $structure = $this->thesisService->getStructure($project);

        return $this->json(['success' => true, 'data' => ['structure' => $structure]]);
    }

    /**
     * POST /api/thesis/chapter
     * Body JSON: { "project_id": int, "title": "string", "parent_id": int|null }
     */
    #[Route('/chapter', name: 'api_thesis_add_chapter', methods: ['POST'])]
    #[Route('/structure', name: 'api_thesis_add_chapter_alias', methods: ['POST'])]
    public function addChapter(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['project_id']) || empty($data['title'])) {
            return $this->json(['success' => false, 'error' => ['code' => 400, 'message' => 'Les champs "project_id" et "title" sont requis.']], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $data['project_id']);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Projet non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        try {
            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
            $chapter = $this->thesisService->addChapter($project, $data['title'], $parentId);

            return $this->json([
                'success' => true,
                'data' => [
                    'chapter' => [
                        'id' => $chapter->getId(),
                        'title' => $chapter->getTitle(),
                        'order' => $chapter->getOrder(),
                    ]
                ]
            ], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'error' => ['code' => 400, 'message' => $e->getMessage()]], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * GET /api/thesis/chapter/{id}
     */
    #[Route('/chapter/{id}', name: 'api_thesis_get_chapter', methods: ['GET'])]
    public function getChapter(int $id): JsonResponse
    {
        $chapter = $this->chapterRepository->find($id);

        if (!$chapter || $chapter->getProject()->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Chapitre non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => [
                'chapter' => [
                    'id' => $chapter->getId(),
                    'title' => $chapter->getTitle(),
                    'content' => $chapter->getContent(),
                    'order' => $chapter->getOrder(),
                    'parent_id' => $chapter->getParent() ? $chapter->getParent()->getId() : null,
                ]
            ]
        ]);
    }

    /**
     * PUT /api/thesis/chapter/{id}
     * Body JSON: { "title": "string", "content": "string" }
     */
    #[Route('/chapter/{id}', name: 'api_thesis_update_chapter', methods: ['PUT'])]
    public function updateChapter(int $id, Request $request): JsonResponse
    {
        $chapter = $this->chapterRepository->find($id);

        if (!$chapter || $chapter->getProject()->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Chapitre non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['title'])) {
            return $this->json(['success' => false, 'error' => ['code' => 400, 'message' => 'Le champ "title" est requis.']], Response::HTTP_BAD_REQUEST);
        }

        $content = $data['content'] ?? $chapter->getContent();

        $updatedChapter = $this->thesisService->updateChapter($chapter, $data['title'], $content);

        return $this->json([
            'success' => true,
            'data' => [
                'chapter' => [
                    'id' => $updatedChapter->getId(),
                    'title' => $updatedChapter->getTitle(),
                    'content' => $updatedChapter->getContent(),
                    'updated_at' => $updatedChapter->getUpdatedAt()?->format('c'),
                ]
            ]
        ]);
    }

    /**
     * DELETE /api/thesis/chapter/{id}
     */
    #[Route('/chapter/{id}', name: 'api_thesis_delete_chapter', methods: ['DELETE'])]
    public function deleteChapter(int $id): JsonResponse
    {
        $chapter = $this->chapterRepository->find($id);

        if (!$chapter || $chapter->getProject()->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Chapitre non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        $this->thesisService->deleteChapter($chapter);

        return $this->json(['success' => true, 'data' => null]);
    }

    /**
     * POST /api/thesis/consistency
     * Body JSON: { "project_id": int }
     * Évalue la cohérence de l'ensemble du plan.
     */
    #[Route('/consistency', name: 'api_thesis_consistency', methods: ['POST'])]
    public function getConsistency(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['project_id'])) {
            return $this->json(['success' => false, 'error' => ['code' => 400, 'message' => 'Le champ "project_id" est requis.']], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $data['project_id']);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Projet non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->thesisService->getConsistency($project);

            return $this->json([
                'success' => true,
                'data' => [
                    'response' => $result['response'],
                    'interaction_id' => $result['interaction']->getId(),
                ]
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'error' => ['code' => 400, 'message' => $e->getMessage()]], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return $this->json(['success' => false, 'error' => ['code' => 503, 'message' => 'Service IA temporairement indisponible.']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * POST /api/thesis/write
     * Body JSON: { "chapter_id": int, "prompt": "string" }
     *
     * Génère du contenu pour un chapitre basé sur un prompt utilisateur et le contexte du projet.
     */
    #[Route('/write', name: 'api_thesis_write', methods: ['POST'])]
    public function writeContent(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['chapter_id']) || empty($data['prompt'])) {
            return $this->json(['success' => false, 'error' => ['code' => 400, 'message' => 'Les champs "chapter_id" et "prompt" sont requis.']], Response::HTTP_BAD_REQUEST);
        }

        $chapter = $this->chapterRepository->find((int) $data['chapter_id']);

        if (!$chapter || $chapter->getProject()->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Chapitre non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->thesisService->writeChapter($chapter, $data['prompt']);

            return $this->json([
                'success' => true,
                'data' => [
                    'response' => $result['response'],
                    'interaction_id' => $result['interaction']->getId(),
                ]
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(['success' => false, 'error' => ['code' => 503, 'message' => 'Service IA temporairement indisponible.']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * POST /api/thesis/reorder
     * Body JSON: { "project_id": int, "orders": [{id, parent_id, order}] }
     */
    #[Route('/reorder', name: 'api_thesis_reorder', methods: ['POST'])]
    #[Route('/structure', name: 'api_thesis_reorder_alias', methods: ['PUT'])]
    public function reorder(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['project_id']) || !isset($data['orders'])) {
            return $this->json(['success' => false, 'error' => ['code' => 400, 'message' => 'Les champs "project_id" et "orders" sont requis.']], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $data['project_id']);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Projet non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        $this->thesisService->reorderChapters($project, $data['orders']);

        return $this->json(['success' => true, 'data' => null]);
    }
}
