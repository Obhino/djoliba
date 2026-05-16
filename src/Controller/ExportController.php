<?php

namespace App\Controller;

use App\Message\ExportProjectMessage;
use App\Service\Project\ProjectManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/projects')]
class ExportController extends AbstractController
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private ProjectManager $projectManager
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/export', name: 'api_project_export', methods: ['GET'])]
    public function export(int $id): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Projet non trouvé.'], 404);
        }

        // Dispatch du message pour traitement asynchrone
        $this->messageBus->dispatch(new ExportProjectMessage($project->getId()));

        return $this->json([
            'success' => true,
            'message' => 'L\'exportation a été lancée et sera disponible prochainement.'
        ]);
    }
}
