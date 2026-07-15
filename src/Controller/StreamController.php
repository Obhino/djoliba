<?php

namespace App\Controller;

use App\Service\IA\DeepSeekService;
use App\Service\Project\ProjectManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Attribute\RateLimiter;

/**
 * StreamController — Diffuse les réponses DeepSeek en Server-Sent Events (SSE).
 *
 * Permet un affichage progressif côté client (React, Twig avec EventSource).
 * Format SSE standard : "data: <text>\n\n" par chunk, "data: [DONE]\n\n" à la fin.
 */
#[Route('/api/stream')]
#[IsGranted('ROLE_USER')]
class StreamController extends AbstractController
{
    public function __construct(
        private DeepSeekService $deepSeekService,
        private ProjectManager $projectManager,
    ) {
    }

    /**
     * POST /api/stream
     * Body JSON: { "prompt": "string", "project_id": int, "options": {} }
     *
     * Retourne un flux SSE (Content-Type: text/event-stream).
     */
    #[Route('', name: 'api_stream', methods: ['POST'])]
    #[RateLimiter('api_ia')]
    public function streamResponse(Request $request): StreamedResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['prompt']) || empty($data['project_id'])) {
            // StreamedResponse doit quand même streamer, on envoie une erreur SSE
            return new StreamedResponse(function () {
                echo "data: " . json_encode(['error' => 'Les champs "prompt" et "project_id" sont requis.']) . "\n\n";
                echo "data: [DONE]\n\n";
                flush();
            }, Response::HTTP_BAD_REQUEST, $this->sseHeaders());
        }

        $project = $this->projectManager->getProject((int) $data['project_id']);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return new StreamedResponse(function () {
                echo "data: " . json_encode(['error' => 'Projet non trouvé.']) . "\n\n";
                echo "data: [DONE]\n\n";
                flush();
            }, Response::HTTP_NOT_FOUND, $this->sseHeaders());
        }

        $prompt  = $data['prompt'];
        $options = $data['options'] ?? [];

        return new StreamedResponse(function () use ($prompt, $options) {
            // Désactiver le buffering PHP et Nginx pour un vrai streaming
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            try {
                $this->deepSeekService->stream(
                    $prompt,
                    function (string $chunk) {
                        // Format SSE : chaque fragment est envoyé comme un événement "data"
                        echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                        flush();
                    },
                    $options
                );
            } catch (\RuntimeException $e) {
                echo "data: " . json_encode(['error' => 'Service IA indisponible. Veuillez réessayer.']) . "\n\n";
            }

            // Signal de fin de stream
            echo "data: [DONE]\n\n";
            flush();
        }, Response::HTTP_OK, $this->sseHeaders());
    }

    /**
     * En-têtes HTTP nécessaires pour les Server-Sent Events.
     */
    private function sseHeaders(): array
    {
        return [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',    // Désactive le buffering Nginx
            'Connection'        => 'keep-alive',
        ];
    }
}
