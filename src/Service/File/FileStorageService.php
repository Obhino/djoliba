<?php

namespace App\Service\File;

use App\Entity\Document;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class FileStorageService
{
    private const MAX_FILE_SIZE = 25 * 1024 * 1024; // 25 Mo
    private const MAX_USER_QUOTA = 500 * 1024 * 1024; // 500 Mo par utilisateur

    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/x-tex',
        'text/x-tex',
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    private const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'tex', 'png', 'jpg', 'jpeg', 'webp'];
    private const ALLOWED_CATEGORIES = ['documents', 'exports', 'attachments'];

    /**
     * IMPORTANT SÉCURITÉ : Ce répertoire doit TOUJOURS rester hors de public/.
     * Déplacer ce répertoire dans public/ exposerait les documents de recherche
     * des utilisateurs directement via URL.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentRepository $documentRepository,
        #[Autowire('%kernel.project_dir%/var/uploads')]
        private string $uploadDirectory
    ) {
    }

    /**
     * Valide, upload le fichier physiquement dans une arborescence users/{user_id}/projects/{project_id}/{category}
     * et crée l'entité Document associée.
     *
     * @throws \InvalidArgumentException Si le fichier est invalide (taille, format, quota).
     * @throws \RuntimeException Si une erreur système ou un virus survient.
     */
    public function upload(UploadedFile $file, Project $project, User $user, string $category = 'documents'): Document
    {
        // 1. Validation de la catégorie
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            $category = 'documents';
        }

        // 2. Validation de la taille individuelle (25 Mo max)
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('Le fichier dépasse la taille maximale autorisée de 25 Mo.');
        }

        // 3. Validation du quota utilisateur
        $currentUsage = $this->getUserStorageUsage($user);
        if ($currentUsage + $file->getSize() > self::MAX_USER_QUOTA) {
            throw new \InvalidArgumentException('Quota de stockage utilisateur dépassé (limite de 500 Mo atteinte).');
        }

        // 4. Double validation du format : MIME + extension déduite
        $mimeType = $file->getMimeType();
        $guessedExtension = $file->guessExtension();

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true) || !in_array($guessedExtension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Format de fichier non supporté. Seuls PDF, DOCX, LaTeX et images (PNG, JPEG, WebP) sont acceptés.');
        }

        // 5. Scan antivirus
        $isVirus = $this->scanForVirus($file->getPathname());
        if ($isVirus) {
            throw new \RuntimeException('Opération annulée : un virus a été détecté dans le fichier.');
        }

        // 6. Génération d'un nom unique sécurisé
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '', $originalFilename);

        if (empty($safeFilename)) {
            $safeFilename = 'document';
        }

        $newFilename = $safeFilename . '-' . uniqid('', true) . '.' . $guessedExtension;

        // 7. Construction de l'arborescence structurée : users/{user_id}/projects/{project_id}/{category}
        $relativeSubDir = sprintf('users/%d/projects/%d/%s', $user->getId(), $project->getId(), $category);
        $targetDirectory = $this->uploadDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeSubDir);

        if (!is_dir($targetDirectory)) {
            if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
                throw new \RuntimeException(sprintf('Impossible de créer le répertoire "%s"', $targetDirectory));
            }
        }

        try {
            $file->move($targetDirectory, $newFilename);
        } catch (FileException $e) {
            throw new \RuntimeException('Erreur système lors de l\'enregistrement du fichier : ' . $e->getMessage());
        }

        $finalPath = $targetDirectory . DIRECTORY_SEPARATOR . $newFilename;
        $relativePath = $relativeSubDir . '/' . $newFilename;

        // 8. Création et persistance de l'entité Document
        $document = new Document();
        $document->setProject($project);
        $document->setUser($user);
        $document->setFilename($file->getClientOriginalName());
        $document->setCategory($category);
        $document->setRelativePath($relativePath);
        $document->setStoredPath($finalPath);
        $document->setMimeType($mimeType);
        $document->setSizeBytes(filesize($finalPath));
        $document->setIsScanned(true);
        $document->setVirusFound(false);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    /**
     * Résout le chemin d'accès absolu sur disque pour un document donné (gère la rétrocompatibilité).
     */
    public function getAbsoluteFilePath(Document $document): string
    {
        if ($document->getRelativePath()) {
            return $this->uploadDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $document->getRelativePath());
        }

        return $document->getStoredPath() ?? '';
    }

    /**
     * Récupère le volume total de stockage utilisé par un utilisateur (en octets).
     */
    public function getUserStorageUsage(User $user): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $result = $qb->select('SUM(d.sizeBytes)')
            ->from(Document::class, 'd')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Récupère le volume total de stockage utilisé pour un projet (en octets).
     */
    public function getProjectStorageUsage(Project $project): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $result = $qb->select('SUM(d.sizeBytes)')
            ->from(Document::class, 'd')
            ->where('d.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Récupère un document appartenant à un utilisateur spécifique (Fix IDOR).
     */
    public function getDocument(int $id, User $user): ?Document
    {
        return $this->documentRepository->findOneBy([
            'id'   => $id,
            'user' => $user,
        ]);
    }

    /**
     * Supprime le fichier physique et l'entité en base de données.
     */
    public function deleteDocument(Document $document): void
    {
        $realUploadDir = realpath($this->uploadDirectory);
        $filePath = $this->getAbsoluteFilePath($document);
        $realFilePath  = realpath($filePath);

        if ($realFilePath !== false && $realUploadDir !== false && str_starts_with($realFilePath, $realUploadDir . DIRECTORY_SEPARATOR)) {
            if (file_exists($realFilePath)) {
                @unlink($realFilePath);
            }
        }

        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Scan antivirus — à connecter à ClamAV en production.
     * Librairie recommandée : xenolope/quahog (client PHP pour clamd).
     * Commande directe : exec('clamdscan --no-summary ' . escapeshellarg($filePath), $output, $exitCode);
     * Exit code 0 = OK, 1 = virus trouvé.
     */
    private function scanForVirus(string $filePath): bool
    {
        $clamavUrl = $_ENV['CLAMAV_URL'] ?? getenv('CLAMAV_URL') ?? '';
        if (empty($clamavUrl)) {
            return false;
        }

        try {
            $socket = @stream_socket_client($clamavUrl, $errno, $errstr, 5);
            if (!$socket) {
                if (($_ENV['APP_ENV'] ?? 'dev') === 'prod') {
                    throw new \RuntimeException("Le service antivirus est indisponible : " . $errstr);
                }
                return false;
            }

            // Commande INSTREAM de ClamAV
            fwrite($socket, "zINSTREAM\0");

            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                fclose($socket);
                return false;
            }

            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                $len = strlen($chunk);
                if ($len > 0) {
                    fwrite($socket, pack('N', $len)); // Taille en big-endian
                    fwrite($socket, $chunk);
                }
            }
            fclose($handle);

            // Terminer le flux (taille 0)
            fwrite($socket, pack('N', 0));
            fflush($socket);

            $response = fgets($socket, 128);
            fclose($socket);

            if ($response && str_contains($response, 'FOUND')) {
                return true; // Virus détecté
            }
        } catch (\Exception $e) {
            if (($_ENV['APP_ENV'] ?? 'dev') === 'prod') {
                throw new \RuntimeException("Erreur lors de l'analyse antivirus : " . $e->getMessage());
            }
        }

        return false; // Pas de virus ou scan ignoré (dev/test)
    }
}
