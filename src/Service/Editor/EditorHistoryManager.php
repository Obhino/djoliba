<?php

namespace App\Service\Editor;

use App\Entity\EditorInteraction;
use App\Entity\SubProject;
use App\Entity\User;
use App\Repository\EditorInteractionRepository;
use Doctrine\ORM\EntityManagerInterface;

class EditorHistoryManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EditorInteractionRepository $repository
    ) {
    }

    /**
     * Enregistre une nouvelle interaction de l'éditeur en base de données.
     */
    public function logInteraction(
        ?SubProject $subProject,
        ?User $user,
        string $action,
        ?string $selectedText,
        ?string $suggestion
    ): EditorInteraction {
        $interaction = new EditorInteraction();
        $interaction->setSubProject($subProject);
        $interaction->setUser($user);
        $interaction->setAction($action);
        $interaction->setSelectedText($selectedText);
        $interaction->setSuggestion($suggestion);

        $this->entityManager->persist($interaction);
        $this->entityManager->flush();

        return $interaction;
    }

    /**
     * Met à jour le statut d'acceptation d'une interaction.
     */
    public function updateStatus(int $interactionId, bool $accepted, User $user): ?EditorInteraction
    {
        $interaction = $this->repository->find($interactionId);
        if ($interaction) {
            if ($interaction->getUser() !== $user) {
                return null;
            }
            $interaction->setAccepted($accepted);
            $this->entityManager->flush();
        }

        return $interaction;
    }

    /**
     * Récupère l'historique des interactions pour un sous-projet donné, par ordre chronologique décroissant.
     *
     * @return EditorInteraction[]
     */
    public function getHistory(SubProject $subProject): array
    {
        return $this->repository->findBy(
            ['subProject' => $subProject],
            ['createdAt' => 'DESC']
        );
    }
}
