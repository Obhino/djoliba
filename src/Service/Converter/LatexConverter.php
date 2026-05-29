<?php

namespace App\Service\Converter;

/**
 * LatexConverter
 * 
 * Service de conversion bidirectionnelle basique entre HTML et LaTeX.
 * Conçu pour supporter les commandes courantes en toute légèreté.
 */
class LatexConverter
{
    /**
     * Convertit du HTML (provenant de l'éditeur WYSIWYG) en code LaTeX.
     */
    public function htmlToLatex(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // 1. Nettoyage initial et standardisation des sauts de ligne
        $latex = str_replace(["\r\n", "\r"], "\n", $html);

        // 2. Conversion des balises block de code
        $latex = preg_replace_callback('/<pre><code>(.*?)<\/code><\/pre>/s', function ($matches) {
            $code = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            return "\n\\begin{verbatim}\n" . trim($code) . "\n\\end{verbatim}\n";
        }, $latex);

        // 3. Conversion des Titres
        $latex = preg_replace('/<h1>(.*?)<\/h1>/i', "\n\\section{\\1}\n", $latex);
        $latex = preg_replace('/<h2>(.*?)<\/h2>/i', "\n\\subsection{\\1}\n", $latex);
        $latex = preg_replace('/<h3>(.*?)<\/h3>/i', "\n\\subsubsection{\\1}\n", $latex);
        $latex = preg_replace('/<h4>(.*?)<\/h4>/i', "\n\\paragraph{\\1}\n", $latex);

        // 4. Conversion des Listes à puces (unordered list)
        $latex = preg_replace('/<ul>/i', "\n\\begin{itemize}\n", $latex);
        $latex = preg_replace('/<\/ul>/i', "\n\\end{itemize}\n", $latex);
        $latex = preg_replace('/<li>(.*?)<\/li>/i', "  \\item \\1\n", $latex);

        // 5. Conversion des Listes ordonnées (ordered list)
        $latex = preg_replace('/<ol>/i', "\n\\begin{enumerate}\n", $latex);
        $latex = preg_replace('/<\/ol>/i', "\n\\end{enumerate}\n", $latex);

        // 6. Remplacement des liens hypertextes
        $latex = preg_replace('/<a\s+[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/i', '\\href{\\1}{\\2}', $latex);

        // 7. Formatage Inline (gras, italique)
        $latex = preg_replace('/<strong>(.*?)<\/strong>/i', '\\textbf{\\1}', $latex);
        $latex = preg_replace('/<b>(.*?)<\/b>/i', '\\textbf{\\1}', $latex);
        $latex = preg_replace('/<em>(.*?)<\/em>/i', '\\textit{\\1}', $latex);
        $latex = preg_replace('/<i>(.*?)<\/i>/i', '\\textit{\\1}', $latex);

        // 8. Gestion des paragraphes et retours à la ligne
        $latex = preg_replace('/<p>(.*?)<\/p>/i', "\\1\n\n", $latex);
        $latex = preg_replace('/<br\s*\/?>/i', " \\\\\n", $latex);

        // 9. Nettoyer les balises HTML résiduelles non supportées
        $latex = strip_tags($latex);

        // 10. Décoder les entités HTML restantes
        $latex = html_entity_decode($latex, ENT_QUOTES, 'UTF-8');

        return trim($latex);
    }

    /**
     * Convertit du code LaTeX (LaTeX brut saisi par l'utilisateur) en HTML propre
     * pour l'affichage de la prévisualisation WYSIWYG.
     */
    public function latexToHtml(string $latex): string
    {
        if (empty($latex)) {
            return '';
        }

        // Échapper le HTML d'origine pour éviter les failles XSS
        $html = htmlspecialchars($latex, ENT_QUOTES, 'UTF-8');

        // 1. Conversion des blocs verbatim (verbatim ou code blocks)
        $html = preg_replace_callback('/\\\\begin\{verbatim\}(.*?)\\\\end\{verbatim\}/s', function ($matches) {
            return '<pre class="bg-slate-100 p-4 rounded-xl border border-slate-200 overflow-x-auto text-xs font-mono my-4"><code>' . trim($matches[1]) . '</code></pre>';
        }, $html);

        // 2. Conversion des sections et sous-sections
        $html = preg_replace('/\\\\section\*?\{([^}]+)\}/i', '<h1 class="text-xl font-display font-bold text-djoliba mt-6 mb-3">\\1</h1>', $html);
        $html = preg_replace('/\\\\subsection\*?\{([^}]+)\}/i', '<h2 class="text-lg font-display font-bold text-djoliba mt-5 mb-2">\\1</h2>', $html);
        $html = preg_replace('/\\\\subsubsection\*?\{([^}]+)\}/i', '<h3 class="text-base font-display font-bold text-djoliba mt-4 mb-2">\\1</h3>', $html);
        $html = preg_replace('/\\\\paragraph\*?\{([^}]+)\}/i', '<h4 class="text-sm font-bold text-djoliba mt-3 mb-1">\\1</h4>', $html);

        // 3. Conversion des listes à puces (itemize)
        $html = preg_replace('/\\\\begin\{itemize\}/i', '<ul class="list-disc pl-6 space-y-1 my-3 text-slate-700">', $html);
        $html = preg_replace('/\\\\end\{itemize\}/i', '</ul>', $html);

        // 4. Conversion des listes ordonnées (enumerate)
        $html = preg_replace('/\\\\begin\{enumerate\}/i', '<ol class="list-decimal pl-6 space-y-1 my-3 text-slate-700">', $html);
        $html = preg_replace('/\\\\end\{enumerate\}/i', '</ol>', $html);

        // 5. Conversion des items de listes
        $html = preg_replace('/\\\\item\s+(.*?)(?=\\\\item|\\\\end\{itemize\}|\\\\end\{enumerate\}|\n\n|$)/s', '<li>\\1</li>', $html);

        // 6. Liens hypertextes (\href{url}{label})
        $html = preg_replace('/\\\\href\{([^}]+)\}\{([^}]+)\}/i', '<a href="\\1" class="text-blue-500 hover:text-blue-600 underline font-medium" target="_blank">\\2</a>', $html);

        // 7. Formatage en ligne (textbf, textit)
        $html = preg_replace('/\\\\textbf\{([^}]+)\}/i', '<strong class="font-bold">\\1</strong>', $html);
        $html = preg_replace('/\\\\textit\{([^}]+)\}/i', '<em class="italic">\\1</em>', $html);

        // 8. Gestion des sauts de ligne LaTeX (\\ ou \newline)
        $html = preg_replace('/\\\\\\\\|\\\\newline/i', '<br>', $html);

        // 9. Structuration en paragraphes pour les blocs de texte séparés par double saut de ligne
        $paragraphs = preg_split('/\n{2,}/', $html);
        $htmlOut = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;
            
            // Ne pas entourer de <p> si c'est déjà un élément de bloc HTML (comme h1, h2, pre, ul, ol)
            if (preg_match('/^(<h|<pre|<ul|<ol|<li)/i', $para)) {
                $htmlOut .= $para . "\n";
            } else {
                $htmlOut .= '<p class="text-xs text-slate-800 leading-relaxed my-3">' . $para . '</p>' . "\n";
            }
        }

        return trim($htmlOut);
    }
}
