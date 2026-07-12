<?php

namespace App\Controller\Api;

use App\Service\Project\ProjectManager;
use App\Service\Converter\LatexConverter;
use App\Service\Bibliography\BibliographyExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use App\Service\Converter\PdfGeneratorService;

#[Route('/api/projects')]
#[IsGranted('ROLE_USER')]
class ExportController extends AbstractController
{
    public function __construct(
        private ProjectManager $projectManager,
        private LatexConverter $latexConverter,
        private Environment $twig,
        private BibliographyExporter $bibliographyExporter,
        private PdfGeneratorService $pdfGenerator
    ) {
    }

    /**
     * POST /api/projects/{id}/export/latex
     * Body JSON: { "html": "string" }
     *
     * Reçoit le code HTML de l'éditeur scientifique WYSIWYG,
     * le convertit en LaTeX propre et retourne le fichier .tex en téléchargement.
     */
    #[Route('/{id}/export/latex', name: 'api_project_export_latex_rich', methods: ['POST'])]
    public function exportLatex(int $id, Request $request): Response
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Projet non trouvé.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $html = $data['html'] ?? '';
        $filename = $data['filename'] ?? sprintf('djoliba_export_%d.tex', $id);

        if (!str_ends_with($filename, '.tex')) {
            $filename .= '.tex';
        }

        // Conversion du HTML enrichi en LaTeX standardisé de haute qualité
        $latexContent = $this->latexConverter->htmlToLatex($html);

        // Génération et intégration de la bibliographie
        $keys = $this->bibliographyExporter->extractKeys($html);
        if (!empty($keys)) {
            $references = $this->bibliographyExporter->getReferencesByKeys($this->getUser(), $keys);
            if (!empty($references)) {
                $bibLatex = $this->bibliographyExporter->generateLatex($references);
                $latexContent .= "\n" . $bibLatex;
            }
        }

        $response = new Response($latexContent);
        $response->headers->set('Content-Type', 'application/x-tex');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * POST /api/projects/{id}/export/pdf
     * Body JSON: { "html": "string", "filename": "string" }
     *
     * Reçoit le code HTML de l'éditeur scientifique WYSIWYG,
     * le convertit en PDF de haute qualité et retourne le fichier .pdf en téléchargement.
     */
    #[Route('/{id}/export/pdf', name: 'api_project_export_pdf_rich', methods: ['POST'])]
    public function exportPdf(int $id, Request $request): Response
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Projet non trouvé.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $html = $data['html'] ?? '';
        $filename = $data['filename'] ?? sprintf('djoliba_export_%d.pdf', $id);

        if (!str_ends_with($filename, '.pdf')) {
            $filename .= '.pdf';
        }



        $subProject = $project->getSubProject();
        $bibEntries = $subProject ? $subProject->getBibliographyEntries() : [];
        $bibData = [];
        
        // 1. Ajouter les références spécifiques au sous-projet
        foreach ($bibEntries as $entry) {
            $bibData[] = $entry->toArray();
        }

        // 2. Extraire et ajouter les références globales (user-wide)
        $keys = $this->bibliographyExporter->extractKeys($html);
        if (!empty($keys)) {
            $references = $this->bibliographyExporter->getReferencesByKeys($project->getUser(), $keys);
            foreach ($references as $ref) {
                // Éviter les doublons si déjà présents
                $exists = false;
                foreach ($bibData as $existing) {
                    if (($existing['citeKey'] ?? '') === $ref->getCiteKey()) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $bibData[] = [
                        'citeKey' => $ref->getCiteKey(),
                        'entryType' => $ref->getEntryType(),
                        'title' => $ref->getTitle(),
                        'authors' => $ref->getAuthors(),
                        'authorsFormatted' => $ref->getAuthors(),
                        'year' => $ref->getYear(),
                        'journal' => $ref->getJournal(),
                        'doi' => $ref->getDoi(),
                        'rawData' => $ref->getRawData() ?? [],
                    ];
                }
            }
        }

        $bibEntriesJson = json_encode($bibData);

        // Rendu HTML complet avec le template de prévisualisation et Gotenberg
        $styledHtml = $this->twig->render('export/print.html.twig', [
            'project' => $project,
            'source' => 'editor_server',
            'editorHtml' => $html,
            'bibEntriesJson' => $bibEntriesJson,
        ]);

        $tempPdfPath = tempnam(sys_get_temp_dir(), 'pdf_export_') . '.pdf';

        // Génération du PDF de haute qualité via WeasyPrint
        $this->pdfGenerator->generate($styledHtml, $tempPdfPath, [
            'title' => $project->getName(),
            'author' => $project->getUser()->getFirstName() ?? $project->getUser()->getEmail(),
            'keywords' => $project->getType() ?? '',
            'description' => $project->getResearchProject()?->getDescription() ?? '',
        ]);

        $pdfOutput = file_get_contents($tempPdfPath);
        if (file_exists($tempPdfPath)) {
            unlink($tempPdfPath);
        }

        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }
}
