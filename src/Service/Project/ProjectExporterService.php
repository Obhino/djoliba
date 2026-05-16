<?php

namespace App\Service\Project;

use App\Entity\Chapter;
use App\Entity\Project;
use App\Repository\ChapterRepository;
use App\Repository\DocumentRepository;
use Symfony\Component\Filesystem\Filesystem;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class ProjectExporterService
{
    public function __construct(
        private ChapterRepository $chapterRepository,
        private DocumentRepository $documentRepository,
        private Environment $twig,
        private string $projectDir
    ) {
    }

    /**
     * Exporte un projet complet sous forme de fichier PDF.
     */
    public function exportToPdf(Project $project): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);

        $chapters = $this->chapterRepository->findBy(['project' => $project, 'parent' => null], ['order' => 'ASC']);
        
        $html = $this->twig->render('export/pdf_thesis.html.twig', [
            'project' => $project,
            'chapters' => $chapters,
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $exportDir = $this->projectDir . '/public/uploads/exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0777, true);
        }

        $filename = sprintf('%s_%s.pdf', $this->slugify($project->getName()), uniqid());
        $pdfPath = $exportDir . '/' . $filename;

        file_put_contents($pdfPath, $dompdf->output());

        return $pdfPath;
    }

    /**
     * Exporte un projet complet sous forme de fichier ZIP.
     * 
     * @param Project $project Le projet à exporter
     * @return string Le chemin vers le fichier ZIP généré
     * @throws \RuntimeException Si la génération du ZIP échoue
     */
    public function exportToZip(Project $project): string
    {
        $zip = new \ZipArchive();
        $exportDir = $this->projectDir . '/public/uploads/exports';
        
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0777, true);
        }

        $filename = sprintf('%s_%s.zip', $this->slugify($project->getName()), uniqid());
        $zipPath = $exportDir . '/' . $filename;

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Impossible d'ouvrir le fichier ZIP : $zipPath");
        }

        $baseFolder = $this->slugify($project->getName());

        // 1. Export de la thèse (chapitres)
        $chapters = $this->chapterRepository->findBy(['project' => $project, 'parent' => null], ['order' => 'ASC']);
        $this->addChaptersToZip($zip, $chapters, $baseFolder . '/these');

        // 2. Export des sources (documents)
        $documents = $this->documentRepository->findBy(['project' => $project]);
        foreach ($documents as $document) {
            $path = $document->getStoredPath();
            if (file_exists($path)) {
                $zip->addFile($path, $baseFolder . '/sources/' . $document->getFilename());
            }
        }

        // 3. Métadonnées du projet
        $metadata = [
            'name' => $project->getName(),
            'type' => $project->getType(),
            'created_at' => $project->getCreatedAt()->format('Y-m-d H:i:s'),
            'description' => $project->getDescription(),
            'export_date' => date('Y-m-d H:i:s'),
        ];
        $zip->addFromString($baseFolder . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $zip->close();

        return $zipPath;
    }

    /**
     * Ajoute récursivement les chapitres au ZIP.
     */
    private function addChaptersToZip(\ZipArchive $zip, array $chapters, string $folder, int $level = 1): void
    {
        foreach ($chapters as $index => $chapter) {
            $prefix = sprintf('%02d_', $chapter->getOrder());
            $filename = $prefix . $this->slugify($chapter->getTitle()) . '.md';
            $content = "# " . $chapter->getTitle() . "\n\n" . ($chapter->getContent() ?? "_Chapitre vide_");
            
            $zip->addFromString($folder . '/' . $filename, $content);

            // Enfants
            if (!$chapter->getChildren()->isEmpty()) {
                $subFolder = $folder . '/' . $prefix . $this->slugify($chapter->getTitle());
                $this->addChaptersToZip($zip, $chapter->getChildren()->toArray(), $subFolder, $level + 1);
            }
        }
    }

    public function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '_', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '_');
        $text = strtolower($text);
        return empty($text) ? 'n_a' : $text;
    }
}
