<?php

namespace App\Service\File;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Smalot\PdfParser\Parser as PdfParser;

class TextExtractorService
{
    private const CACHE_TTL = 2592000; // 30 jours en secondes

    public function __construct(
        private CacheInterface $cache
    ) {
    }

    /**
     * Extrait le texte d'un fichier avec mise en cache locale.
     */
    public function extractText(string $path, string $mimeType, string $filename): string
    {
        $realPath = $path;
        if (!file_exists($realPath)) {
            // Tenter de résoudre le chemin de manière relative au projet (corrige les chemins mixtes Windows/Docker)
            $projectRoot = dirname(__DIR__, 3);
            $filenameInPath = basename(str_replace('\\', '/', $path));
            $alternativePath = $projectRoot . '/var/uploads/' . $filenameInPath;
            if (file_exists($alternativePath)) {
                $realPath = $alternativePath;
            } else {
                throw new \RuntimeException(sprintf('Fichier physique introuvable : %s', $path));
            }
        }

        // Fichiers texte (LaTeX, .tex) : lecture directe très rapide, pas besoin de cache complexe
        if (in_array($mimeType, ['application/x-tex', 'text/x-tex'], true)) {
            return file_get_contents($realPath);
        }

        // PDF : extraction via smalot/pdfparser avec cache Redis/Local
        if ($mimeType === 'application/pdf') {
            $fileSize = filesize($realPath);
            $fileTime = filemtime($realPath);
            $cacheKey = 'extracted_text_pdf_' . md5($realPath . '_' . $fileSize . '_' . $fileTime);

            try {
                return $this->cache->get($cacheKey, function (ItemInterface $item) use ($realPath) {
                    $item->expiresAfter(self::CACHE_TTL);
                    
                    $parser = new PdfParser();
                    $pdf = $parser->parseFile($realPath);
                    return $pdf->getText();
                });
            } catch (\Exception $e) {
                throw new \RuntimeException(sprintf('Erreur lors de la lecture du PDF : %s', $e->getMessage()), 0, $e);
            }
        }

        return sprintf(
            "[Contenu du fichier %s — extraction binaire non encore implémentée. Fichier de type : %s]",
            $filename,
            $mimeType
        );
    }
}
