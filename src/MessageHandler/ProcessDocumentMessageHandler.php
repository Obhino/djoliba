<?php

namespace App\MessageHandler;

use App\Message\ProcessDocumentMessage;
use App\Repository\DocumentRepository;
use App\Repository\ProjectRepository;
use App\Service\IA\CacheService;
use App\Service\ReadingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler asynchrone pour le traitement des documents uploadés.
 *
 * Flux : Upload → DocumentPostPersistSubscriber → ProcessDocumentMessage (queue)
 *     → Ce handler (worker) → synthesize() → cache Redis 1h
 *
 * Exécuté par : php bin/console messenger:consume async
 */
#[AsMessageHandler]
class ProcessDocumentMessageHandler
{
    public function __construct(
        private ReadingService     $readingService,
        private DocumentRepository $documentRepository,
        private ProjectRepository  $projectRepository,
        private CacheService       $cacheService,
        private LoggerInterface    $logger,
    ) {
    }

    public function __invoke(ProcessDocumentMessage $message): void
    {
        $documentId = $message->getDocumentId();
        $projectId  = $message->getProjectId();

        $this->logger->info('[ProcessDocument] Début du traitement du document #{id}', ['id' => $documentId]);

        // 1. Récupérer le document et le projet
        $document = $this->documentRepository->find($documentId);
        $project  = $this->projectRepository->find($projectId);

        if ($document === null) {
            $this->logger->error('[ProcessDocument] Document #{id} introuvable, message abandonné.', ['id' => $documentId]);
            return;
        }

        if ($project === null) {
            $this->logger->error('[ProcessDocument] Projet #{id} introuvable, message abandonné.', ['id' => $projectId]);
            return;
        }

        // 2. Vérifier si le document a déjà été traité (cache présent)
        $cacheKey = 'synthesis_doc_' . $documentId;
        $isCached = $this->cacheService->remember(
            $cacheKey . '_processed',
            fn() => false,
            1 // TTL de 1 seconde : simple vérification d'existence
        );

        // 3. Générer la synthèse et la mettre en cache
        try {
            $result = $this->readingService->synthesize($document, $project);

            $this->logger->info(
                '[ProcessDocument] Synthèse générée pour le document #{id} ({count} points clés).',
                ['id' => $documentId, 'count' => count($result['points'])]
            );

        } catch (\InvalidArgumentException $e) {
            // Fichier physique absent → ne pas retenter (le fichier ne reviendra pas)
            $this->logger->warning(
                '[ProcessDocument] Fichier physique absent pour le document #{id} : {error}',
                ['id' => $documentId, 'error' => $e->getMessage()]
            );
            return;

        } catch (\RuntimeException $e) {
            // API DeepSeek indisponible → lancer l'exception pour que Messenger retente
            $this->logger->error(
                '[ProcessDocument] Échec API pour le document #{id} : {error}. Le message sera retenté.',
                ['id' => $documentId, 'error' => $e->getMessage()]
            );
            throw $e; // Messenger va mettre le message en "failed" après les retries
        }
    }
}
