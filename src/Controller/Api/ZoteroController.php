<?php

namespace App\Controller\Api;

use App\Repository\SubProjectRepository;
use App\Service\Bibliography\ZoteroService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Security\EncryptionService;

/**
 * ZoteroController — Contrôleur API pour la configuration et la synchronisation avec Zotero.
 */
#[Route('/api')]
#[IsGranted('ROLE_USER')]
class ZoteroController extends AbstractController
{
    public function __construct(
        private SubProjectRepository $subProjectRepository,
        private ZoteroService $zoteroService,
        private EntityManagerInterface $entityManager,
        private EncryptionService $encryptionService,
    ) {
    }

    /**
     * POST /api/sub-projects/{id}/zotero/config
     *
     * Enregistre les identifiants de l'API Zotero dans les métadonnées du sous-projet.
     * Body JSON : { "zotero_user_id": "...", "zotero_api_key": "..." }
     */
    #[Route('/sub-projects/{id}/zotero/config', name: 'api_zotero_config_save', methods: ['POST'])]
    public function saveConfig(int $id, Request $request): JsonResponse
    {
        $subProject = $this->subProjectRepository->find($id);

        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Sous-projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $zoteroUserId = trim($data['zotero_user_id'] ?? '');
        $zoteroApiKey = trim($data['zotero_api_key'] ?? '');

        if (empty($zoteroUserId) || empty($zoteroApiKey)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Les identifiants Zotero ne peuvent pas être vides.']
            ], Response::HTTP_BAD_REQUEST);
        }

        // Valider auprès de Zotero
        if (!$this->zoteroService->validateCredentials($zoteroUserId, $zoteroApiKey)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Identifiants Zotero invalides ou inaccessibles.']
            ], Response::HTTP_BAD_REQUEST);
        }

        // Sauvegarder dans metadata en chiffrant la clé API
        $metadata = $subProject->getMetadata() ?? [];
        $metadata['zotero_user_id'] = $zoteroUserId;
        $metadata['zotero_api_key'] = $this->encryptionService->encrypt($zoteroApiKey);

        $subProject->setMetadata($metadata);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Configuration Zotero enregistrée et validée avec succès.'
        ]);
    }

    /**
     * GET /api/sub-projects/{id}/zotero/config
     *
     * Récupère la configuration Zotero (masquée pour la clé API).
     */
    #[Route('/sub-projects/{id}/zotero/config', name: 'api_zotero_config_get', methods: ['GET'])]
    public function getConfig(int $id): JsonResponse
    {
        $subProject = $this->subProjectRepository->find($id);

        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Sous-projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $metadata = $subProject->getMetadata() ?? [];
        $zoteroUserId = $metadata['zotero_user_id'] ?? '';
        $zoteroApiKey = $metadata['zotero_api_key'] ?? '';

        // Masquer la clé API pour la sécurité (ex: s******)
        $maskedKey = '';
        if ($zoteroApiKey) {
            $decryptedApiKey = $this->encryptionService->decrypt($zoteroApiKey);
            $maskedKey = substr($decryptedApiKey, 0, 3) . str_repeat('*', max(6, strlen($decryptedApiKey) - 6)) . substr($decryptedApiKey, -3);
        }

        return $this->json([
            'success' => true,
            'data'    => [
                'zotero_user_id' => $zoteroUserId,
                'zotero_api_key' => $maskedKey,
                'configured'     => !empty($zoteroUserId) && !empty($zoteroApiKey)
            ]
        ]);
    }

    /**
     * GET /api/sub-projects/{id}/zotero/collections
     *
     * Récupère les collections Zotero de l'utilisateur.
     */
    #[Route('/sub-projects/{id}/zotero/collections', name: 'api_zotero_collections', methods: ['GET'])]
    public function getCollections(int $id): JsonResponse
    {
        $subProject = $this->subProjectRepository->find($id);

        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Sous-projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $metadata = $subProject->getMetadata() ?? [];
        $zoteroUserId = $metadata['zotero_user_id'] ?? '';
        $zoteroApiKey = $metadata['zotero_api_key'] ?? '';

        if (empty($zoteroUserId) || empty($zoteroApiKey)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Veuillez d\'abord configurer Zotero pour ce projet.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $decryptedApiKey = $this->encryptionService->decrypt($zoteroApiKey);
        $collections = $this->zoteroService->fetchCollections($zoteroUserId, $decryptedApiKey);

        return $this->json([
            'success' => true,
            'data'    => [
                'collections' => $collections
            ]
        ]);
    }

    /**
     * GET /api/sub-projects/{id}/zotero/search
     *
     * Recherche de références Zotero.
     * Paramètres :
     * - q : terme de recherche
     * - collection : clé d'une collection spécifique
     */
    #[Route('/sub-projects/{id}/zotero/search', name: 'api_zotero_search', methods: ['GET'])]
    public function searchItems(int $id, Request $request): JsonResponse
    {
        $subProject = $this->subProjectRepository->find($id);

        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Sous-projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $metadata = $subProject->getMetadata() ?? [];
        $zoteroUserId = $metadata['zotero_user_id'] ?? '';
        $zoteroApiKey = $metadata['zotero_api_key'] ?? '';

        if (empty($zoteroUserId) || empty($zoteroApiKey)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Veuillez d\'abord configurer Zotero pour ce projet.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $search        = $request->query->get('q');
        $collectionKey = $request->query->get('collection');

        $decryptedApiKey = $this->encryptionService->decrypt($zoteroApiKey);
        $items = $this->zoteroService->fetchItems($zoteroUserId, $decryptedApiKey, $collectionKey, $search);

        return $this->json([
            'success' => true,
            'data'    => [
                'items' => $items,
                'count' => count($items)
            ]
        ]);
    }

    /**
     * POST /api/sub-projects/{id}/zotero/import
     *
     * Importe des références spécifiques depuis Zotero.
     * Body JSON : { "keys": ["ABCD1234", "EFGH5678"] }
     */
    #[Route('/sub-projects/{id}/zotero/import', name: 'api_zotero_import', methods: ['POST'])]
    public function importItems(int $id, Request $request): JsonResponse
    {
        $subProject = $this->subProjectRepository->find($id);

        if (!$subProject || $subProject->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Sous-projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $metadata = $subProject->getMetadata() ?? [];
        $zoteroUserId = $metadata['zotero_user_id'] ?? '';
        $zoteroApiKey = $metadata['zotero_api_key'] ?? '';

        if (empty($zoteroUserId) || empty($zoteroApiKey)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Veuillez d\'abord configurer Zotero pour ce projet.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $keys = $data['keys'] ?? [];

        if (empty($keys) || !is_array($keys)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Veuillez spécifier au moins une clé Zotero à importer sous forme de tableau JSON.']
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $decryptedApiKey = $this->encryptionService->decrypt($zoteroApiKey);
            $result = $this->zoteroService->importSelectedItems($subProject, $keys, $zoteroUserId, $decryptedApiKey);

            return $this->json([
                'success' => true,
                'data'    => [
                    'imported' => $result['imported'],
                    'updated'  => $result['updated'],
                    'message'  => sprintf('%d référence(s) importée(s) et %d mise(s) à jour depuis Zotero.', $result['imported'], $result['updated'])
                ]
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 500, 'message' => $e->getMessage()]
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
