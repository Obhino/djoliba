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

        // 0. Conversion des citations bibliographiques <cite data-cite-key> → \cite{key}
        $html = $this->convertCitations($html);

        // 1. Nettoyage initial et standardisation des sauts de ligne
        $latex = str_replace(["\r\n", "\r"], "\n", $html);

        // 2. Traitement des Notes de bas de page (Footnotes)
        $footnotes = [];
        preg_match_all('/<p\s+id="fn(\d+)"[^>]*>(.*?)<\/p>/is', $latex, $fnMatches, PREG_SET_ORDER);
        foreach ($fnMatches as $match) {
            $fnId = $match[1];
            $fnText = trim(strip_tags($match[2]));
            // Enlever le préfixe éventuel comme [1] du texte
            $fnText = preg_replace('/^\[\d+\]\s*/', '', $fnText);
            $footnotes[$fnId] = $fnText;
        }

        // Remplacer les exposants de note de bas de page par \footnote{texte}
        $latex = preg_replace_callback('/<sup\s+data-fn="(\d+)"[^>]*>.*?<\/sup>/is', function ($matches) use ($footnotes) {
            $fnId = $matches[1];
            $fnText = $footnotes[$fnId] ?? '';
            return empty($fnText) ? '' : '\\footnote{' . $fnText . '}';
        }, $latex);

        // Supprimer la section footnotes et les paragraphes de note résiduels du bas du document
        $latex = preg_replace('/<div\s+class="footnotes"[^>]*>.*?<\/div>/is', '', $latex);
        $latex = preg_replace('/<p\s+id="fn\d+"[^>]*>.*?<\/p>/is', '', $latex);

        // 3. Traitement des Figures légendées
        $latex = preg_replace_callback('/<figure\s+data-label="([^"]*)"[^>]*>(.*?)<\/figure>/is', function ($matches) {
            $label = trim($matches[1]);
            $content = $matches[2];

            preg_match('/<img\s+[^>]*src="([^"]+)"/i', $content, $imgMatch);
            $src = $imgMatch[1] ?? '';
            
            preg_match('/<figcaption[^>]*>(.*?)<\/figcaption>/is', $content, $capMatch);
            $caption = isset($capMatch[1]) ? trim(strip_tags($capMatch[1])) : '';

            if (empty($src)) {
                return '';
            }

            $filename = basename($src);

            $figLatex = "\n\\begin{figure}[h!]\n";
            $figLatex .= "  \\centering\n";
            $figLatex .= "  \\includegraphics[width=0.8\\textwidth]{" . $filename . "}\n";
            if (!empty($caption)) {
                $figLatex .= "  \\caption{" . $caption . "}\n";
            }
            if (!empty($label)) {
                $figLatex .= "  \\label{" . $label . "}\n";
            }
            $figLatex .= "\\end{figure}\n";

            return $figLatex;
        }, $latex);

        // 4. Traitement des Tableaux
        $latex = preg_replace_callback('/<table([^>]*)>(.*?)<\/table>/is', function ($matches) {
            $attributes = $matches[1];
            $tableContent = $matches[2];
            
            // Extraire data-caption et data-label si présents
            preg_match('/data-caption="([^"]*)"/i', $attributes, $captionMatch);
            $caption = isset($captionMatch[1]) ? trim(html_entity_decode($captionMatch[1], ENT_QUOTES, 'UTF-8')) : '';

            preg_match('/data-label="([^"]*)"/i', $attributes, $labelMatch);
            $label = isset($labelMatch[1]) ? trim($labelMatch[1]) : '';

            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableContent, $trMatches);
            $rows = [];
            $maxCols = 0;

            foreach ($trMatches[1] as $trContent) {
                preg_match_all('/<(td|th)[^>]*>(.*?)<\/\1>/is', $trContent, $tdMatches);
                $cells = [];
                foreach ($tdMatches[2] as $cellContent) {
                    $cells[] = trim(strip_tags($cellContent));
                }
                if (count($cells) > 0) {
                    $rows[] = $cells;
                    $maxCols = max($maxCols, count($cells));
                }
            }

            if ($maxCols === 0) {
                return '';
            }

            $colFormat = '|' . str_repeat('c|', $maxCols);

            $tblLatex = "\n\\begin{table}[h!]\n";
            $tblLatex .= "  \\centering\n";
            if (!empty($caption)) {
                $tblLatex .= "  \\caption{" . $caption . "}\n";
            }
            if (!empty($label)) {
                $tblLatex .= "  \\label{" . $label . "}\n";
            }
            $tblLatex .= "  \\begin{tabular}{" . $colFormat . "}\n";
            $tblLatex .= "    \\hline\n";

            foreach ($rows as $cells) {
                while (count($cells) < $maxCols) {
                    $cells[] = '';
                }
                $tblLatex .= "    " . implode(' & ', $cells) . " \\\\ \\hline\n";
            }

            $tblLatex .= "  \\end{tabular}\n";
            $tblLatex .= "\\end{table}\n";

            return $tblLatex;
        }, $latex);

        // 5. Conversion des balises block de code
        $latex = preg_replace_callback('/<pre><code>(.*?)<\/code><\/pre>/s', function ($matches) {
            $code = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            return "\n\\begin{verbatim}\n" . trim($code) . "\n\\end{verbatim}\n";
        }, $latex);

        // 6. Conversion des Titres
        $latex = preg_replace('/<h1>(.*?)<\/h1>/i', "\n\\section{\\1}\n", $latex);
        $latex = preg_replace('/<h2>(.*?)<\/h2>/i', "\n\\subsection{\\1}\n", $latex);
        $latex = preg_replace('/<h3>(.*?)<\/h3>/i', "\n\\subsubsection{\\1}\n", $latex);
        $latex = preg_replace('/<h4>(.*?)<\/h4>/i', "\n\\paragraph{\\1}\n", $latex);

        // 7. Conversion des Listes à puces (unordered list)
        $latex = preg_replace('/<ul>/i', "\n\\begin{itemize}\n", $latex);
        $latex = preg_replace('/<\/ul>/i', "\n\\end{itemize}\n", $latex);
        $latex = preg_replace('/<li>(.*?)<\/li>/i', "  \\item \\1\n", $latex);

        // 8. Conversion des Listes ordonnées (ordered list)
        $latex = preg_replace('/<ol>/i', "\n\\begin{enumerate}\n", $latex);
        $latex = preg_replace('/<\/ol>/i', "\n\\end{enumerate}\n", $latex);

        // 9. Remplacement des Références croisées (liens internes #label)
        $latex = preg_replace('/<a\s+[^>]*href="#([^"]+)"[^>]*>(.*?)<\/a>/i', '\\ref{\1}', $latex);

        // 10. Remplacement des liens hypertextes externes
        $latex = preg_replace('/<a\s+[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/i', '\\href{\\1}{\\2}', $latex);

        // 11. Formatage Inline (gras, italique)
        $latex = preg_replace('/<strong>(.*?)<\/strong>/i', '\\textbf{\\1}', $latex);
        $latex = preg_replace('/<b>(.*?)<\/b>/i', '\\textbf{\\1}', $latex);
        $latex = preg_replace('/<em>(.*?)<\/em>/i', '\\textit{\\1}', $latex);
        $latex = preg_replace('/<i>(.*?)<\/i>/i', '\\textit{\\1}', $latex);

        // 12. Gestion des paragraphes et retours à la ligne
        $latex = preg_replace('/<p>(.*?)<\/p>/i', "\\1\n\n", $latex);
        $latex = preg_replace('/<br\s*\/?>/i', " \\\\\n", $latex);

        // 13. Nettoyer les balises HTML résiduelles non supportées
        $latex = strip_tags($latex);

        // 14. Décoder les entités HTML restantes
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

    // ─────────────────────────────────────────────────────────────────────────
    // BIBLIOGRAPHIE — Support des citations \cite et du bloc bibliography
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convertit les balises <cite data-cite-key="…"> insérées par CitationNode
     * en commandes LaTeX \cite{citeKey}.
     *
     * Doit être appelée AVANT la conversion globale HTML→LaTeX pour que les
     * balises <cite> soient traitées en priorité.
     *
     * @param string $html HTML brut de l'éditeur
     * @return string HTML avec les <cite> remplacés par \cite{key}
     */
    public function convertCitations(string $html): string
    {
        return preg_replace_callback(
            '/<cite\s+[^>]*data-cite-key="([^"]+)"[^>]*>[^<]*<\/cite>/i',
            static function (array $matches): string {
                $citeKey = trim($matches[1]);
                // On retourne la commande LaTeX brute ; elle sera préservée
                // par les étapes suivantes de la conversion.
                return '\\cite{' . $citeKey . '}';
            },
            $html
        ) ?? $html;
    }

    /**
     * Génère le bloc LaTeX \begin{thebibliography}{99}…\end{thebibliography}
     * à partir d'un tableau d'entités BibliographyEntry.
     *
     * @param \App\Entity\BibliographyEntry[] $entries
     * @return string Code LaTeX du bloc bibliographique
     */
    public function generateBibliography(array $entries): string
    {
        if (empty($entries)) {
            return '';
        }

        $lines = [];
        $lines[] = '';
        $lines[] = '% ─── Bibliographie générée automatiquement par Djoliba ───';
        $lines[] = '\begin{thebibliography}{' . count($entries) . '}';
        $lines[] = '';

        foreach ($entries as $entry) {
            $citeKey = $entry->getCiteKey();
            $raw     = $entry->getRawData() ?? [];

            // Reconstruction d'une entrée \bibitem condensée
            $author   = $this->latexEscape($entry->getAuthors() ?? 'Anonyme');
            $title    = $this->latexEscape($entry->getTitle() ?? '');
            $year     = $this->latexEscape($entry->getYear() ?? '');
            $journal  = $this->latexEscape($entry->getJournal() ?? '');
            $volume   = $this->latexEscape($raw['volume'] ?? '');
            $pages    = $raw['pages'] ?? '';
            $doi      = $entry->getDoi() ?? '';

            $lines[] = '\bibitem{' . $citeKey . '}';
            $lines[] = $author . '.';

            if ($title) {
                $lines[] = '\textit{' . $title . '}.';
            }

            // Ligne journal/source
            $source = [];
            if ($journal) $source[] = $journal;
            if ($volume)  $source[] = $volume;
            if ($year)    $source[] = '(' . $year . ')';
            if ($pages)   $source[] = str_replace('--', '--', $pages);

            if ($source) {
                $lines[] = implode(', ', $source) . '.';
            }

            if ($doi) {
                $lines[] = 'DOI: \href{https://doi.org/' . $doi . '}{' . $doi . '}.';
            }

            $lines[] = '';
        }

        $lines[] = '\end{thebibliography}';

        return implode("\n", $lines);
    }

    /**
     * Échappe les caractères spéciaux LaTeX dans une chaîne de texte brut.
     */
    public function latexEscape(string $text): string
    {
        // Ordre important : backslash en premier
        $search  = ['\\', '{', '}', '$', '#', '%', '&', '^', '_', '~', '<', '>'];
        $replace = ['\textbackslash{}', '\{', '\}', '\$', '\#', '\%', '\&', '\^{}', '\_', '\~{}', '\textless{}', '\textgreater{}'];

        return str_replace($search, $replace, $text);
    }
}
