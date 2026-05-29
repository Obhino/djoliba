<?php

namespace App\Controller\Api;

use App\Service\Project\ProjectManager;
use App\Service\Converter\LatexConverter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
#[IsGranted('ROLE_USER')]
class EditorController extends AbstractController
{
    public function __construct(
        private ProjectManager $projectManager,
        private LatexConverter $latexConverter,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * POST /api/projects/{id}/content
     * Body JSON: { "content": "string", "mode": "string" }
     *
     * Enregistre automatiquement le contenu et le mode de l'éditeur dans les métadonnées du projet.
     */
    #[Route('/projects/{id}/content', name: 'api_project_save_content', methods: ['POST'])]
    public function saveContent(int $id, Request $request): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $contentWysiwyg = $data['content_wysiwyg'] ?? null;
        $contentLatex = $data['content_latex'] ?? null;
        // Rétrocompatibilité au cas où un ancien payload sans séparation est envoyé
        $contentLegacy = $data['content'] ?? null;
        $mode = $data['mode'] ?? 'wysiwyg';

        $metadata = $project->getMetadata() ?? [];
        
        if ($contentWysiwyg !== null) {
            $metadata['writing_content_wysiwyg'] = $contentWysiwyg;
        } elseif ($contentLegacy !== null && $mode === 'wysiwyg') {
            $metadata['writing_content_wysiwyg'] = $contentLegacy;
        }

        if ($contentLatex !== null) {
            $metadata['writing_content_latex'] = $contentLatex;
        } elseif ($contentLegacy !== null && $mode === 'latex') {
            $metadata['writing_content_latex'] = $contentLegacy;
        }

        // Toujours garder 'writing_content' synchronisé avec l'éditeur actif pour compatibilité export/preview
        $metadata['writing_content'] = ($mode === 'latex') 
            ? ($metadata['writing_content_latex'] ?? '') 
            : ($metadata['writing_content_wysiwyg'] ?? '');
            
        $metadata['writing_mode'] = $mode;

        $project->setMetadata($metadata);
        $project->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'project_id' => $project->getId(),
                'updated_at' => $project->getUpdatedAt()->format('c')
            ]
        ]);
    }

    /**
     * GET /api/projects/{id}/content
     *
     * Récupère le contenu et le mode de rédaction précédemment sauvegardés.
     */
    #[Route('/projects/{id}/content', name: 'api_project_get_content', methods: ['GET'])]
    public function getContent(int $id): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $metadata = $project->getMetadata() ?? [];
        $contentWysiwyg = $metadata['writing_content_wysiwyg'] ?? $metadata['writing_content'] ?? '';
        $contentLatex = $metadata['writing_content_latex'] ?? $metadata['writing_content'] ?? '';
        $mode = $metadata['writing_mode'] ?? 'wysiwyg';

        return $this->json([
            'success' => true,
            'data' => [
                'content_wysiwyg' => $contentWysiwyg,
                'content_latex' => $contentLatex,
                'mode' => $mode
            ]
        ]);
    }

    /**
     * POST /api/export/latex
     * Body JSON: { "content": "string", "filename": "string|null" }
     *
     * Convertit le contenu en LaTeX s'il est au format HTML/WYSIWYG,
     * puis sert le fichier .tex au téléchargement.
     */
    #[Route('/export/latex', name: 'api_editor_export_latex', methods: ['POST'])]
    public function exportLatex(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $content = $data['content'] ?? '';
        $filename = $data['filename'] ?? 'document.tex';

        if (!str_ends_with($filename, '.tex')) {
            $filename .= '.tex';
        }

        // Si le contenu contient des balises HTML, on le convertit proprement en LaTeX
        if (str_contains($content, '<p>') || str_contains($content, '<h1>') || str_contains($content, '<strong>')) {
            $content = $this->latexConverter->htmlToLatex($content);
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/x-tex');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * POST /api/convert/latex-to-html
     * Body JSON: { "content": "string" }
     *
     * Convertit du LaTeX en HTML léger pour la prévisualisation WYSIWYG.
     */
    #[Route('/convert/latex-to-html', name: 'api_editor_convert_latex_to_html', methods: ['POST'])]
    public function convertLatexToHtml(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $latex = $data['content'] ?? '';

        $html = $this->latexConverter->latexToHtml($latex);

        return $this->json([
            'success' => true,
            'data' => [
                'html' => $html
            ]
        ]);
    }
}
