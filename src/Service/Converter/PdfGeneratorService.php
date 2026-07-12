<?php

namespace App\Service\Converter;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class PdfGeneratorService
{
    public function __construct(
        private string $projectDir,
        private ?string $weasyprintBinary = null,
        private ?HttpClientInterface $httpClient = null,
        private ?string $gotenbergUrl = null,
        private ?string $appBaseUrl = null
    ) {
    }

    /**
     * Génère un fichier PDF à partir de contenu HTML en utilisant Gotenberg.
     *
     * @param string $html Le contenu HTML complet (déjà rendu)
     * @param string $outputPath Le chemin de destination du fichier PDF
     * @param array $metadata Les métadonnées du document (title, author, keywords, etc.)
     * @throws \RuntimeException Si la génération échoue
     */
    public function generate(string $html, string $outputPath, array $metadata = []): void
    {
        $fs = new Filesystem();
        
        // S'assurer que le dossier de destination existe
        $outputDir = dirname($outputPath);
        if (!$fs->exists($outputDir)) {
            $fs->mkdir($outputDir, 0777);
        }

        // 1. Prétraiter le HTML pour la bibliographie, les DOI et la TOC interactive
        $processedHtml = $this->processHtml($html, $metadata);

        // 2. Injecter la balise <base> pour résoudre les ressources relatives (CSS, images)
        if ($this->appBaseUrl) {
            $processedHtml = $this->injectBaseTag($processedHtml, $this->appBaseUrl);
        }

        // 3. Envoyer la requête à Gotenberg
        if (!$this->httpClient) {
            throw new \RuntimeException("HttpClientInterface non disponible pour la génération PDF Gotenberg.");
        }

        $gotenbergUrl = $this->gotenbergUrl ?: 'http://localhost:3000';
        
        // Si Symfony CLI injecte l'URL Docker avec le schéma tcp://, on le remplace par http://
        if (str_starts_with($gotenbergUrl, 'tcp://')) {
            $gotenbergUrl = 'http://' . substr($gotenbergUrl, 6);
        }
        
        $gotenbergEndpoint = rtrim($gotenbergUrl, '/') . '/forms/chromium/convert/html';

        try {
            // Créer le body multipart/form-data
            $fields = [
                'files' => new DataPart($processedHtml, 'index.html', 'text/html'),
                'waitWindowStatus' => 'ready'
            ];
            $formData = new FormDataPart($fields);

            $response = $this->httpClient->request('POST', $gotenbergEndpoint, [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
                'timeout' => 120, // Temps large pour les longs manuscrits
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \RuntimeException(sprintf("Gotenberg a retourné une erreur (statut %d) : %s", $statusCode, $response->getContent(false)));
            }

            $fs->dumpFile($outputPath, $response->getContent());
        } catch (\Exception $e) {
            throw new \RuntimeException("La génération PDF via Gotenberg a échoué : " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Tente de localiser le binaire de WeasyPrint sur le système.
     */
    private function findWeasyPrintBinary(): array
    {
        if ($this->weasyprintBinary && trim($this->weasyprintBinary) !== '') {
            if (file_exists($this->weasyprintBinary)) {
                return [$this->weasyprintBinary];
            }
            if (str_contains($this->weasyprintBinary, ' ')) {
                return array_filter(explode(' ', $this->weasyprintBinary));
            }
            return [$this->weasyprintBinary];
        }

        $checks = [
            ['weasyprint'],
            ['python', '-m', 'weasyprint'],
            ['py', '-m', 'weasyprint'],
        ];

        foreach ($checks as $check) {
            $testCmd = array_merge($check, ['--version']);
            $process = new Process($testCmd);
            $process->run();
            if ($process->isSuccessful()) {
                return $check;
            }
        }

        // Fallback par défaut si non détecté
        return ['weasyprint'];
    }

    /**
     * Traite le HTML brut pour ajouter les ancres, la TOC interactive, les métadonnées et formater les DOI.
     */
    public function processHtml(string $html, array $metadata): string
    {
        // Nettoyer et formater les liens DOI dans la bibliographie
        $html = $this->formatDoiLinks($html);

        // Injecter des balises meta pour les métadonnées PDF de WeasyPrint
        $html = $this->injectMetadataTags($html, $metadata);

        // Générer et injecter la Table des Matières Interactive
        $html = $this->buildInteractiveToc($html);

        return $html;
    }

    /**
     * Injecte les balises de métadonnées dans l'en-tête <head> du document HTML.
     */
    private function injectMetadataTags(string $html, array $metadata): string
    {
        $title = htmlspecialchars($metadata['title'] ?? 'Document Académique');
        $author = htmlspecialchars($metadata['author'] ?? 'Chercheur Djoliba');
        $keywords = htmlspecialchars($metadata['keywords'] ?? 'Djoliba, Academic, PDF');
        $description = htmlspecialchars($metadata['description'] ?? '');

        $metaTags = sprintf(
            "\n    <meta name=\"author\" content=\"%s\">\n" .
            "    <meta name=\"keywords\" content=\"%s\">\n" .
            "    <meta name=\"description\" content=\"%s\">\n",
            $author,
            $keywords,
            $description
        );

        // Remplacer le titre ou l'injecter sous <head>
        if (preg_match('/<head>/i', $html)) {
            $html = preg_replace('/<head>/i', '<head>' . $metaTags, $html);
        }

        return $html;
    }

    /**
     * Formate les DOI de la bibliographie pour en faire des liens cliquables
     */
    private function formatDoiLinks(string $html): string
    {
        return preg_replace(
            '/\bdoi:?\s*(10\.\d{4,9}\/[^\s,;<>]+)/i',
            '<a href="https://doi.org/$1" class="doi-link" target="_blank">https://doi.org/$1</a>',
            $html
        );
    }

    /**
     * Analyse le document HTML, génère des IDs uniques pour les titres H1/H2
     * et construit une table des matières interactive basée sur les règles CSS Paged Media.
     */
    private function buildInteractiveToc(string $html): string
    {
        // Si la TOC est déjà présente statiquement dans les templates (ex: pdf_thesis),
        // on s'assure juste d'ajouter des IDs cohérents sur les titres
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // Charger le HTML en UTF-8
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $headings = $xpath->query('//h1 | //h2');
        
        $tocItemsHtml = '';
        $idCounter = 1;

        foreach ($headings as $heading) {
            $text = trim($heading->textContent);
            $nodeName = strtolower($heading->nodeName);
            
            // Éviter les titres comme "Sommaire" ou "Bibliographie" dans la table des matières
            if (in_array(strtolower($text), ['sommaire', 'table des matières', 'bibliographie', 'références'])) {
                continue;
            }

            // Générer un ID unique pour l'ancre si absent
            $id = $heading->getAttribute('id');
            if (empty($id)) {
                $id = 'section-' . $idCounter++;
                $heading->setAttribute('id', $id);
            }

            $levelClass = ($nodeName === 'h1') ? 'level-1' : 'level-2';
            $tocItemsHtml .= sprintf(
                '<li class="toc-item %s"><a href="#%s">%s</a></li>',
                $levelClass,
                $id,
                htmlspecialchars($text)
            );
        }

        $modifiedHtml = $dom->saveHTML();
        $modifiedHtml = str_replace('<?xml encoding="utf-8" ?>', '', $modifiedHtml);

        // Si l'utilisateur a prévu un placeholder pour la TOC interactive
        if (str_contains($modifiedHtml, '<!-- TOC_PLACEHOLDER -->')) {
            $tocBlockHtml = '';
            if (!empty($tocItemsHtml)) {
                $tocBlockHtml = sprintf(
                    '<div class="toc-container"><div class="toc-title">Table des Matières</div><ul class="toc-list">%s</ul></div>',
                    $tocItemsHtml
                );
            }
            $modifiedHtml = str_replace('<!-- TOC_PLACEHOLDER -->', $tocBlockHtml, $modifiedHtml);
        } else {
            // S'il n'y a pas de TOC mais que le document est long (plus de 3 grands titres),
            // on injecte la TOC juste au début du contenu
            if ($idCounter > 3) {
                $tocBlockHtml = sprintf(
                    '<div class="toc-container page-break"><div class="toc-title">Table des Matières</div><ul class="toc-list">%s</ul></div>',
                    $tocItemsHtml
                );
                
                // Injecter après le titre de garde ou au début de body
                if (preg_match('/<div class="content">/i', $modifiedHtml)) {
                    $modifiedHtml = preg_replace('/<div class="content">/i', '<div class="content">' . $tocBlockHtml, $modifiedHtml);
                }
            }
        }

        return $modifiedHtml;
    }

    /**
     * Injecte une balise <base href="..."> au début du <head> pour résoudre les ressources relatives.
     */
    private function injectBaseTag(string $html, string $baseUrl): string
    {
        if (empty($baseUrl)) {
            return $html;
        }

        // Si l'application tourne en local (localhost/127.0.0.1) et est appelée par Gotenberg (dans Docker),
        // Gotenberg doit contacter l'hôte via 'host.docker.internal' en HTTPS (car symfony server:start tourne en HTTPS)
        // en ignorant les erreurs de certificat SSL (configuré via CHROMIUM_IGNORE_CERTIFICATE_ERRORS=true).
        if (str_contains($baseUrl, 'localhost') || str_contains($baseUrl, '127.0.0.1')) {
            $baseUrl = str_replace(['localhost', '127.0.0.1'], 'host.docker.internal', $baseUrl);
            $baseUrl = str_replace('http://', 'https://', $baseUrl);
        }

        $baseTag = sprintf("\n    <base href=\"%s\">\n", rtrim($baseUrl, '/') . '/');

        // Insérer le <base> tag au début du <head>
        if (preg_match('/<head>/i', $html)) {
            $html = preg_replace('/<head>/i', '<head>' . $baseTag, $html);
        }

        return $html;
    }
}
