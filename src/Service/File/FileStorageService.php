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
    
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/x-tex',
        'text/x-tex',
        'text/plain' // Souvent utilisé pour les fichiers .tex
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentRepository $documentRepository,
        #[Autowire('%kernel.project_dir%/var/uploads')]
        private string $uploadDirectory
    ) {
    }

    /**
     * Valide, upload le fichier physiquement et crée l'entité Document associée.
     *
     * @throws \InvalidArgumentException Si le fichier est invalide (taille, format).
     * @throws \RuntimeException Si une erreur système ou un virus survient.
     */
    public function upload(UploadedFile $file, Project $project, User $user): Document
    {
        // 1. Validation de la taille (25 Mo)
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('Le fichier dépasse la taille maximale autorisée de 25 Mo.');
        }

        // 2. Validation du format (MIME type)
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException('Format de fichier non supporté. Seuls PDF, DOCX et LaTeX sont acceptés.');
        }

        // 3. Scan antivirus
        $isVirus = $this->scanForVirus($file->getPathname());
        if ($isVirus) {
            throw new \RuntimeException('Opération annulée : un virus a été détecté dans le fichier.');
        }

        // 4. Génération d'un nom unique sécurisé et sauvegarde physique
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_.-]/', '', $originalFilename);
        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        try {
            $file->move($this->uploadDirectory, $newFilename);
        } catch (FileException $e) {
            throw new \RuntimeException('Erreur système lors de l\'enregistrement du fichier : ' . $e->getMessage());
        }

        $finalPath = $this->uploadDirectory . '/' . $newFilename;

        // 5. Création et persistance de l'entité Document
        $document = new Document();
        $document->setProject($project);
        $document->setUser($user);
        $document->setFilename($file->getClientOriginalName());
        $document->setStoredPath($finalPath);
        $document->setMimeType($mimeType);
        $document->setSizeBytes(filesize($finalPath));
        $document->setIsScanned(true); // Considéré scanné puisque c'est fait en amont
        $document->setVirusFound(false);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    public function getDocument(int $id): ?Document
    {
        return $this->documentRepository->find($id);
    }

    public function deleteDocument(Document $document): void
    {
        // 1. Suppression physique du fichier s'il existe toujours
        if (file_exists($document->getStoredPath())) {
            unlink($document->getStoredPath());
        }

        // 2. Suppression en base de données
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Simule un scan antivirus.
     * Pour la production, utiliser ClamAV ou une API tierce.
     */
    private function scanForVirus(string $filePath): bool
    {
        // TODO: Implémentation réelle de ClamAV (ex: via exec('clamdscan ...'))
        // Pour l'instant, on retourne false (pas de virus)
        return false;
    }
}
