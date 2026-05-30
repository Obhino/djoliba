<?php

namespace App\Controller;

use App\Message\ExportProjectMessage;
use App\Service\Project\ProjectManager;
use App\Service\Project\ProjectExporterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/projects')]
class ExportController extends AbstractController
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private ProjectManager $projectManager,
        private ProjectExporterService $exporterService,
        private string $projectDir
    ) {
    }

    /**
     * Déclenche la génération asynchrone d'un export.
     *
     * Réponse :
     *   { "success": true, "jobId": "abc123", "message": "..." }
     *
     * Le client doit ensuite appeler GET /api/projects/{id}/export/status?jobId=…
     * pour savoir quand l'export est prêt.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/export', name: 'api_project_export', methods: ['GET'])]
    public function export(int $id, Request $request): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Projet non trouvé.'], 404);
        }

        $format = $request->query->get('format', 'zip');
        $jobId  = bin2hex(random_bytes(16)); // UUID-like unique identifier

        // ── Écriture immédiate du statut "pending" ──────────────────────────
        $this->writeJobStatus($jobId, [
            'status'    => 'pending',
            'format'    => $format,
            'projectId' => $id,
            'createdAt' => (new \DateTime())->format(\DateTime::ATOM),
        ]);

        // ── Dispatch asynchrone ─────────────────────────────────────────────
        $this->messageBus->dispatch(
            new ExportProjectMessage($project->getId(), $format, $jobId)
        );

        return $this->json([
            'success' => true,
            'jobId'   => $jobId,
            'message' => 'Export lancé. Vérifiez le statut via /api/projects/' . $id . '/export/status?jobId=' . $jobId,
        ]);
    }

    /**
     * Retourne l'état courant d'un job d'export.
     *
     * Réponse possible :
     *   { "status": "pending" }
     *   { "status": "done",  "downloadUrl": "/project/123/export/zip" }
     *   { "status": "error", "error": "Message d'erreur" }
     *   { "status": "not_found" }
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/export/status', name: 'api_project_export_status', methods: ['GET'])]
    public function status(int $id, Request $request): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Projet non trouvé.'], 404);
        }

        $jobId = $request->query->get('jobId', '');

        if (!$jobId || !preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            return $this->json(['status' => 'not_found', 'error' => 'jobId invalide.'], 400);
        }

        $jobFile = $this->projectDir . '/var/export_jobs/' . $jobId . '.json';

        if (!file_exists($jobFile)) {
            return $this->json(['status' => 'not_found']);
        }

        $data = json_decode(file_get_contents($jobFile), true);

        if (!is_array($data)) {
            return $this->json(['status' => 'error', 'error' => 'Fichier de statut corrompu.'], 500);
        }

        // Sécurité : vérifier que le job appartient bien à ce projet
        if (isset($data['projectId']) && (int) $data['projectId'] !== $id) {
            return $this->json(['status' => 'not_found'], 403);
        }

        return $this->json($data);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function writeJobStatus(string $jobId, array $data): void
    {
        $jobsDir = $this->projectDir . '/var/export_jobs';

        if (!is_dir($jobsDir)) {
            mkdir($jobsDir, 0775, true);
        }

        file_put_contents(
            $jobsDir . '/' . $jobId . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
