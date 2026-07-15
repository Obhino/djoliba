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
        // NOTE: 'text/plain' a été retiré intentionnellement (fix sécurité).
        // text/plain accepte n'importe quel fichier texte (scripts PHP, JS, etc.).
        // Seuls application/x-tex et text/x-tex sont acceptés pour les fichiers LaTeX.
    ];

    private const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'tex'];

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
     * Valide, upload le fichier physiquement et crée l'entité Document associée.
     *
     * @throws \InvalidArgumentException Si le fichier est invalide (taille, format).
     * @throws \RuntimeException Si une erreur système ou un virus survient.
     */
    public function upload(UploadedFile $file, Project $project, User $user): Document
    {
        // 1. Validation de la taille (25 Mo max)
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('Le fichier dépasse la taille maximale autorisée de 25 Mo.');
        }

        // 2. Double validation du format : MIME (via finfo/magic bytes) + extension déduite
        //    Ces deux méthodes analysent le CONTENU du fichier, pas le nom fourni par l'utilisateur.
        $mimeType = $file->getMimeType();
        $guessedExtension = $file->guessExtension();

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES) || !in_array($guessedExtension, self::ALLOWED_EXTENSIONS)) {
            throw new \InvalidArgumentException('Format de fichier non supporté. Seuls PDF, DOCX et LaTeX (.tex) sont acceptés.');
        }

        // 3. Scan antivirus
        $isVirus = $this->scanForVirus($file->getPathname());
        if ($isVirus) {
            throw new \RuntimeException('Opération annulée : un virus a été détecté dans le fichier.');
        }

        // 4. Génération d'un nom unique sécurisé
        //    On utilise uniquement l'extension déduite (guessExtension), jamais celle fournie par le client.
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '', $originalFilename);

        // Fix: prévenir un safeFilename vide si le nom original était composé de caractères spéciaux
        if (empty($safeFilename)) {
            $safeFilename = 'document';
        }

        $newFilename = $safeFilename . '-' . uniqid() . '.' . $guessedExtension;

        try {
            $file->move($this->uploadDirectory, $newFilename);
        } catch (FileException $e) {
            throw new \RuntimeException('Erreur système lors de l\'enregistrement du fichier : ' . $e->getMessage());
        }

        $finalPath = $this->uploadDirectory . DIRECTORY_SEPARATOR . $newFilename;

        // 5. Création et persistance de l'entité Document
        $document = new Document();
        $document->setProject($project);
        $document->setUser($user);
        $document->setFilename($file->getClientOriginalName());
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
     * Récupère un document appartenant à un utilisateur spécifique.
     * Fix IDOR : on filtre toujours par user pour éviter qu'un utilisateur
     * accède aux documents d'un autre en devinant l'ID.
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
     * Fix Path Traversal : on vérifie que le chemin résolu est bien dans le répertoire d'upload
     * avant d'appeler unlink(), pour éviter la suppression de fichiers système en cas de
     * valeur corrompue dans storedPath.
     */
    public function deleteDocument(Document $document): void
    {
        $realUploadDir = realpath($this->uploadDirectory);
        $realFilePath  = realpath($document->getStoredPath());

        if ($realFilePath !== false && $realUploadDir !== false && str_starts_with($realFilePath, $realUploadDir . DIRECTORY_SEPARATOR)) {
            unlink($realFilePath);
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
