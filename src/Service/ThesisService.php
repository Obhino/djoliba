<?php

namespace App\Service;

use App\Entity\Chapter;
use App\Entity\Interaction;
use App\Entity\Project;
use App\Repository\ChapterRepository;
use App\Repository\DocumentRepository;
use App\Service\IA\CacheService;
use App\Service\IA\DeepSeekService;
use Doctrine\ORM\EntityManagerInterface;

class ThesisService
{
    public function __construct(
        private ChapterRepository      $chapterRepository,
        private DocumentRepository     $documentRepository,
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
        $currentContent = $chapter->getContent();
        $hasContentChanged = ($currentContent ?? '') !== ($content ?? '');
        $hasTitleChanged = $chapter->getTitle() !== $title;

        if ($hasTitleChanged || $hasContentChanged) {
            $chapter->setTitle($title);
            $chapter->setContent($content);
            $chapter->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();
        }

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
    /**
     * Génère du contenu pour un chapitre spécifique.
     * Utilise le contexte des documents du projet et des autres chapitres existants.
     *
     * @return array{response: string, interaction: Interaction}
     */
    public function writeChapter(Chapter $chapter, string $userPrompt): array
    {
        $project = $chapter->getProject();
        
        // 1. Contexte des autres chapitres (pour la cohérence)
        $otherChapters = $this->chapterRepository->findBy(['project' => $project]);
        $chaptersSummary = [];
        foreach ($otherChapters as $c) {
            if ($c->getId() === $chapter->getId()) continue;
            $chaptersSummary[] = sprintf("- %s: %s", $c->getTitle(), mb_substr((string)$c->getContent(), 0, 300));
        }
        $chaptersContext = implode("\n", $chaptersSummary);

        // 2. Contexte des documents du projet
        $documents = $this->documentRepository->findBy(['project' => $project]);
        $docsContext = [];
        foreach ($documents as $doc) {
            $docsContext[] = "- Document: " . $doc->getFilename();
        }
        $documentsList = implode("\n", $docsContext);

        $prompt = sprintf(
            "Tu es un assistant de rédaction académique expert. Rédige le contenu pour le chapitre suivant d'une thèse.\n\n" .
            "Projet: %s\n" .
            "Chapitre cible: %s\n\n" .
            "Contexte des autres chapitres:\n%s\n\n" .
            "Documents de référence disponibles:\n%s\n\n" .
            "Instruction de l'utilisateur: %s\n\n" .
            "Rédige un contenu structuré, académique et fluide. Cite des éléments si nécessaire.",
            $project->getName(),
            $chapter->getTitle(),
            $chaptersContext ?: "Aucun autre chapitre défini.",
            $documentsList ?: "Aucun document importé.",
            $userPrompt
        );

        $startTime = microtime(true);
        $aiResponse = $this->deepSeekService->call($prompt, ['temperature' => 0.7]);
        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Traçabilité
        $interaction = new Interaction();
        $interaction->setProject($project);
        $interaction->setType('thesis_write');
        $interaction->setUserPrompt($userPrompt);
        $interaction->setAiResponse($aiResponse);
        $interaction->setResponseTimeMs($responseTimeMs);

        $this->entityManager->persist($interaction);
        $this->entityManager->flush();

        return [
            'response'    => $aiResponse,
            'interaction' => $interaction,
        ];
    }

    /**
     * Réorganise les chapitres d'un projet.
     * $orders: array<{id: int, parent_id: int|null, order: int}>
     */
    public function reorderChapters(Project $project, array $orders): void
    {
        foreach ($orders as $item) {
            $chapter = $this->chapterRepository->find($item['id']);
            if (!$chapter || $chapter->getProject() !== $project) continue;

            $parent = null;
            if (!empty($item['parent_id'])) {
                $parent = $this->chapterRepository->find($item['parent_id']);
            }

            $chapter->setParent($parent);
            $chapter->setOrder($item['order']);
        }

        $this->entityManager->flush();
    }
}
