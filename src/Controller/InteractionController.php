<?php

namespace App\Controller;

use App\Entity\Interaction;
use App\Service\Project\ProjectManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/interaction')]
class InteractionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectManager $projectManager,
    ) {
    }

    #[Route('', name: 'api_interaction_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['project_id']) || empty($data['type']) || empty($data['user_prompt'])) {
            return $this->json(['error' => 'Champs requis manquants'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        $isTestMode = $request->hasSession() ? $request->getSession()?->get('is_test_mode') : false;

        if (!$user && !$isTestMode) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_UNAUTHORIZED);
        }

        if ($user) {
            $project = $this->projectManager->getProject((int) $data['project_id']);
            if (!$project || $project->getUser() !== $user) {
                return $this->json(['error' => 'Projet non trouvé'], Response::HTTP_NOT_FOUND);
            }

            try {
                $interaction = new Interaction();
                $interaction->setProject($project);
                $interaction->setType($data['type']);
                $interaction->setUserPrompt($data['user_prompt']);
                $interaction->setAiResponse($data['ai_response'] ?? null);
                
                // Mettre à jour la date de modification du projet
                $project->setUpdatedAt(new \DateTime());

                $this->entityManager->persist($interaction);
                $this->entityManager->flush();

                return $this->json([
                    'success' => true,
                    'data' => ['id' => $interaction->getId()]
                ], Response::HTTP_CREATED);
            } catch (\Exception $e) {
                return $this->json(['error' => 'Erreur lors de la sauvegarde : ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } else {
            // Mode Test : Simulation de la sauvegarde
            $session = $request->hasSession() ? $request->getSession() : null;
            $projects = $session ? $session->get('test_projects', []) : [];
            $found = false;
            foreach ($projects as &$p) {
                if ($p['id'] == $data['project_id']) {
                    if (!isset($p['history'])) $p['history'] = [];
                    $p['history'][] = [
                        'type' => $data['type'],
                        'prompt' => $data['user_prompt'],
                        'response' => $data['ai_response'] ?? null,
                        'date' => (new \DateTime())->format('c')
                    ];
                    $found = true;
                    break;
                }
            }
            if (!$found) return $this->json(['error' => 'Projet non trouvé'], Response::HTTP_NOT_FOUND);
            $session->set('test_projects', $projects);

            return $this->json(['success' => true, 'test_mode' => true]);
        }
    }
}
