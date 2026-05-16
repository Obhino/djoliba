<?php

namespace App\Service;

use App\Entity\Chapter;
use App\Entity\Interaction;
use App\Entity\Project;
use App\Repository\ChapterRepository;
use App\Service\IA\CacheService;
use App\Service\IA\DeepSeekService;
use Doctrine\ORM\EntityManagerInterface;

class ThesisService
{
    public function __construct(
        private ChapterRepository      $chapterRepository,
        private EntityManagerInterface $entityManager,
        private DeepSeekService        $deepSeekService,
        private CacheService           $cacheService,
    ) {
    }

    /**
     * Retourne l'arborescence des chapitres du projet.
     *
     * @return array
     */
    public function getStructure(Project $project): array
    {
        $chapters = $this->chapterRepository->findBy(
            ['project' => $project],
            ['parent' => 'ASC', 'order' => 'ASC']
        );

        $structure = [];
        $childrenMap = [];

        foreach ($chapters as $chapter) {
            $chapterData = [
                'id'      => $chapter->getId(),
                'title'   => $chapter->getTitle(),
                'content' => $chapter->getContent(),
                'order'   => $chapter->getOrder(),
                'children'=> []
            ];

            if ($chapter->getParent() === null) {
                $structure[$chapter->getId()] = $chapterData;
            } else {
                $parentId = $chapter->getParent()->getId();
                if (!isset($childrenMap[$parentId])) {
                    $childrenMap[$parentId] = [];
                }
                $childrenMap[$parentId][] = $chapterData;
            }
        }

        // Assemblage de l'arbre
        $buildTree = function (&$nodes) use (&$buildTree, &$childrenMap) {
            foreach ($nodes as &$node) {
                if (isset($childrenMap[$node['id']])) {
                    $node['children'] = $childrenMap[$node['id']];
                    $buildTree($node['children']);
                }
            }
        };

        $buildTree($structure);

        return array_values($structure);
    }

    /**
     * Ajoute un chapitre au projet.
     */
    public function addChapter(Project $project, string $title, ?int $parentId = null): Chapter
    {
        $chapter = new Chapter();
        $chapter->setProject($project);
        $chapter->setTitle($title);

        $parent = null;
        if ($parentId !== null) {
            $parent = $this->chapterRepository->find($parentId);
            if ($parent && $parent->getProject() !== $project) {
                throw new \InvalidArgumentException('Le chapitre parent n\'appartient pas au même projet.');
            }
            $chapter->setParent($parent);
        }

        // Calculer l'ordre
        $siblings = $this->chapterRepository->findBy(['project' => $project, 'parent' => $parent]);
        $order = count($siblings) + 1;
        $chapter->setOrder($order);

        $this->entityManager->persist($chapter);
        $this->entityManager->flush();

        return $chapter;
    }

    /**
     * Met à jour le titre et le contenu d'un chapitre.
     */
    public function updateChapter(Chapter $chapter, string $title, ?string $content): Chapter
    {
        $chapter->setTitle($title);
        $chapter->setContent($content);
        $chapter->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        return $chapter;
    }

    /**
     * Supprime un chapitre et tous ses sous-chapitres.
     */
    public function deleteChapter(Chapter $chapter): void
    {
        $this->deleteChapterRecursive($chapter);
        $this->entityManager->flush();
    }

    private function deleteChapterRecursive(Chapter $chapter): void
    {
        $children = $this->chapterRepository->findBy(['parent' => $chapter]);
        foreach ($children as $child) {
            $this->deleteChapterRecursive($child);
        }
        $this->entityManager->remove($chapter);
    }

    /**
     * Évalue la cohérence entre les différents chapitres de la thèse.
     * Utilise DeepSeekService avec un cache Redis de 1 heure.
     *
     * @return array{response: string, interaction: Interaction}
     */
    public function getConsistency(Project $project): array
    {
        $chapters = $this->chapterRepository->findBy(
            ['project' => $project],
            ['parent' => 'ASC', 'order' => 'ASC']
        );

        if (empty($chapters)) {
            throw new \InvalidArgumentException('Le projet ne contient aucun chapitre pour évaluer la cohérence.');
        }

        $summary = [];
        foreach ($chapters as $chapter) {
            $parentInfo = $chapter->getParent() ? " (Parent: " . $chapter->getParent()->getTitle() . ")" : "";
            $summary[] = sprintf(
                "Chapitre %d: %s%s\nContenu: %s",
                $chapter->getOrder(),
                $chapter->getTitle(),
                $parentInfo,
                mb_substr((string) $chapter->getContent(), 0, 1000) // Limiter la taille pour l'API
            );
        }
        $thesisContext = implode("\n\n", $summary);

        // On utilise un hash du contexte pour la clé de cache
        $cacheKey = 'thesis_consistency_' . hash('sha256', $thesisContext);

        $prompt = sprintf(
            "Analyse la cohérence globale de ce plan de thèse et de ses chapitres. Identifie les ruptures logiques, les redondances ou les éléments manquants entre les chapitres.\n\nStructure et extraits:\n%s",
            $thesisContext
        );

        $startTime = microtime(true);

        $aiResponse = $this->cacheService->remember(
            $cacheKey,
            function () use ($prompt) {
                return $this->deepSeekService->call($prompt, ['temperature' => 0.3]);
            },
            3600 // 1h
        );

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Traçabilité
        $interaction = new Interaction();
        $interaction->setProject($project);
        $interaction->setType('thesis_consistency');
        $interaction->setUserPrompt("Évaluation de la cohérence de la thèse");
        $interaction->setAiResponse($aiResponse);
        $interaction->setResponseTimeMs($responseTimeMs);

        $this->entityManager->persist($interaction);
        $this->entityManager->flush();

        return [
            'response'    => $aiResponse,
            'interaction' => $interaction,
        ];
    }
}
