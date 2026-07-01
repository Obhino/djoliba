<?php

namespace App\Controller\Api;

use App\Service\Project\ProjectManager;
use App\Service\Converter\LatexConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Dompdf\Dompdf;
use Dompdf\Options;

#[Route('/api/projects')]
#[IsGranted('ROLE_USER')]
class ExportController extends AbstractController
{
    public function __construct(
        private ProjectManager $projectManager,
        private LatexConverter $latexConverter,
        private Environment $twig
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

        // Configuration Dompdf
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);

        // Rendu HTML complet avec le template et la feuille de styles académique
        $styledHtml = $this->twig->render('export/pdf_editor.html.twig', [
            'project' => $project,
            'contentHtml' => $html
        ]);

        $dompdf->loadHtml($styledHtml);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfOutput = $dompdf->output();

        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }
}
