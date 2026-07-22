<?php

namespace App\Controller\Api;

use App\Entity\Document;
use App\Entity\Project;
use App\Repository\DocumentRepository;
use App\Repository\ProjectRepository;
use App\Service\File\FileStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Attribute\RateLimiter;

#[Route('/api/files')]
#[IsGranted('ROLE_USER')]
class FileController extends AbstractController
{
    public function __construct(
        private FileStorageService $fileStorageService,
        private DocumentRepository $documentRepository,
        private ProjectRepository $projectRepository
    ) {
    }

    /**
     * GET /api/files/projects/{projectId}
     * Liste tous les fichiers d'un projet donnés, groupés par catégorie.
     */
    #[Route('/projects/{projectId}', name: 'api_files_list_by_project', methods: ['GET'])]
    #[RateLimiter('api_default')]
    public function listByProject(int $projectId): JsonResponse
    {
        $project = $this->projectRepository->find($projectId);
        if (!$project || !$this->isProjectAccessible($project)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Projet non trouvé ou accès refusé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $documents = $this->documentRepository->findBy(['project' => $project], ['createdAt' => 'DESC']);

        $grouped = [
            'documents'   => [],
            'exports'     => [],
            'attachments' => [],
        ];

        $totalProjectBytes = 0;

        foreach ($documents as $doc) {
            $category = $doc->getCategory() ?? 'documents';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }

            $size = $doc->getSizeBytes() ?? 0;
            $totalProjectBytes += $size;

            $grouped[$category][] = [
                'id'            => $doc->getId(),
                'filename'      => $doc->getFilename(),
                'category'      => $category,
                'mimeType'      => $doc->getMimeType(),
                'sizeBytes'     => $size,
                'formattedSize' => $this->formatBytes($size),
                'createdAt'     => $doc->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'downloadUrl'   => $this->generateUrl('api_files_download', ['id' => $doc->getId()]),
                'previewUrl'    => $this->generateUrl('api_files_preview', ['id' => $doc->getId()]),
            ];
        }

        return $this->json([
            'success' => true,
            'data'    => [
                'projectId'          => $project->getId(),
                'projectName'        => $project->getName(),
                'totalBytes'         => $totalProjectBytes,
                'formattedTotalSize' => $this->formatBytes($totalProjectBytes),
                'files'              => $grouped,
            ]
        ]);
    }

    /**
     * GET /api/files/{id}/download
     * Télécharge de manière sécurisée un fichier en pièce jointe.
     */
    #[Route('/{id}/download', name: 'api_files_download', methods: ['GET'])]
    #[RateLimiter('api_default')]
    public function download(int $id): Response
    {
        $document = $this->documentRepository->find($id);
        if (!$document || !$this->isDocumentAccessible($document)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Fichier non trouvé ou accès refusé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->fileStorageService->getAbsoluteFilePath($document);
        if (!file_exists($filePath)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Le fichier physique est introuvable sur le disque.']
            ], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getFilename()
        );

        if ($document->getMimeType()) {
            $response->headers->set('Content-Type', $document->getMimeType());
        }

        return $response;
    }

    /**
     * GET /api/files/{id}/preview
     * Affiche/streame un fichier de manière sécurisée en ligne (inline).
     */
    #[Route('/{id}/preview', name: 'api_files_preview', methods: ['GET'])]
    #[RateLimiter('api_default')]
    public function preview(int $id): Response
    {
        $document = $this->documentRepository->find($id);
        if (!$document || !$this->isDocumentAccessible($document)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Fichier non trouvé ou accès refusé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->fileStorageService->getAbsoluteFilePath($document);
        if (!file_exists($filePath)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Le fichier physique est introuvable sur le disque.']
            ], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $document->getFilename()
        );

        if ($document->getMimeType()) {
            $response->headers->set('Content-Type', $document->getMimeType());
        }

        return $response;
    }

    /**
     * DELETE /api/files/{id}
     * Supprime physiquement et en base de données un fichier.
     */
    #[Route('/{id}', name: 'api_files_delete', methods: ['DELETE'])]
    #[RateLimiter('api_default')]
    public function delete(int $id): JsonResponse
    {
        $document = $this->documentRepository->find($id);
        if (!$document || !$this->isDocumentAccessible($document)) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Fichier non trouvé ou accès refusé.']
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->fileStorageService->deleteDocument($document);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 500, 'message' => 'Erreur lors de la suppression : ' . $e->getMessage()]
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'success' => true,
            'message' => 'Fichier supprimé avec succès.'
        ]);
    }

    /**
     * GET /api/files/user/storage-stats
     * Bilan d'utilisation du stockage de l'utilisateur connecté.
     */
    #[Route('/user/storage-stats', name: 'api_files_user_storage_stats', methods: ['GET'])]
    #[RateLimiter('api_default')]
    public function storageStats(): JsonResponse
    {
        $user = $this->getUser();
        $usedBytes = $this->fileStorageService->getUserStorageUsage($user);
        $maxBytes = 500 * 1024 * 1024; // 500 Mo
        $percentageUsed = $maxBytes > 0 ? round(($usedBytes / $maxBytes) * 100, 2) : 0;

        return $this->json([
            'success' => true,
            'data'    => [
                'usedBytes'        => $usedBytes,
                'formattedUsed'    => $this->formatBytes($usedBytes),
                'maxBytes'         => $maxBytes,
                'formattedMax'     => $this->formatBytes($maxBytes),
                'percentageUsed'   => $percentageUsed,
            ]
        ]);
    }

    /**
     * Vérifie si le projet est accessible par l'utilisateur connecté.
     */
    private function isProjectAccessible(Project $project): bool
    {
        $user = $this->getUser();
        if (!$user) {
            return false;
        }

        if ($project->getUser() === $user) {
            return true;
        }

        foreach ($project->getMembers() as $member) {
            if ($member->getUser() === $user) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie si le document est accessible par l'utilisateur connecté.
     */
    private function isDocumentAccessible(Document $document): bool
    {
        $user = $this->getUser();
        if (!$user) {
            return false;
        }

        if ($document->getUser() === $user) {
            return true;
        }

        if ($document->getProject() && $this->isProjectAccessible($document->getProject())) {
            return true;
        }

        return false;
    }

    /**
     * Formate un nombre d'octets en unité lisible (Ko, Mo, Go).
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'Ko', 'Mo', 'Go'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
