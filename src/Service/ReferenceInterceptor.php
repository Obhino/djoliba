<?php

namespace App\Service;

class ReferenceInterceptor
{
    public function __construct(
        private ReferenceExtractor $extractor,
        private ReferenceCorrector $corrector
    ) {
    }

    /**
     * Intercepte un texte de revue de littérature, extrait les références,
     * les vérifie via OpenSERP, et réécrit le texte avec des badges et des liens HTML.
     *
     * @param string $text Le texte Markdown original
     * @return string Le texte Markdown enrichi
     */
    public function intercept(string $text): string
    {
        $extractedRefs = $this->extractor->extract($text);
        if (empty($extractedRefs)) {
            return $text;
        }

        $replacements = [];

        foreach ($extractedRefs as $ref) {
            $verification = $this->corrector->verify($ref);

            $raw = $ref['raw'];
            $url = $verification['url'];
            $verified = $verification['verified'];
            $corrected = $verification['corrected'];
            $extractedDoi = $verification['doi'];

            if ($verified) {
                if ($ref['type'] === 'doi') {
                    $targetDoi = $extractedDoi ?: $raw;
                    $targetUrl = $url ?: 'https://doi.org/' . $targetDoi;
                    $isCorrected = ($extractedDoi && strtolower($extractedDoi) !== strtolower($raw));
                    
                    $badge = $isCorrected ? ' 🔄 (corrigé)' : ' ✅';
                    $replacements[$raw] = sprintf('<a href="%s" target="_blank">%s</a>%s', $targetUrl, $targetDoi, $badge);
                } elseif ($ref['type'] === 'arxiv') {
                    $cleanId = trim(str_ireplace('arxiv:', '', $raw));
                    $targetUrl = $url ?: 'https://arxiv.org/abs/' . $cleanId;
                    
                    $replacements[$raw] = sprintf('<a href="%s" target="_blank">%s</a> ✅', $targetUrl, $raw);
                } else {
                    // Référence textuelle (bibliographie)
                    $cleanLine = $ref['query'];
                    $targetUrl = $url ?: 'https://scholar.google.com/scholar?q=' . urlencode($cleanLine);
                    
                    $startPos = strpos($raw, $cleanLine);
                    if ($startPos !== false && !empty($targetUrl)) {
                        $resultTitle = $verification['title'] ?: $cleanLine;
                        
                        // Si un vrai DOI valide est trouvé
                        if ($extractedDoi) {
                            // Vérifier si la citation contenait déjà un DOI (potentiellement faux)
                            if (preg_match('#\b10\.\d{4,9}/[-._;()/:A-Za-z0-9]+\b#', $cleanLine, $oldDoiMatches)) {
                                $oldDoi = $oldDoiMatches[0];
                                if (strtolower($oldDoi) !== strtolower($extractedDoi)) {
                                    $resultTitle = str_replace($oldDoi, $extractedDoi, $resultTitle);
                                    $corrected = true;
                                }
                            } else {
                                // Si aucun DOI n'est présent, on l'ajoute à la fin du titre
                                $resultTitle .= ' (DOI: ' . $extractedDoi . ')';
                                $corrected = true;
                            }
                        }

                        $badge = $corrected ? ' 🔄 (corrigé)' : ' ✅';
                        $lineReplacement = sprintf('<a href="%s" target="_blank">%s</a>%s', $targetUrl, $resultTitle, $badge);
                        
                        $replacements[$raw] = substr($raw, 0, $startPos) . $lineReplacement . substr($raw, $startPos + strlen($cleanLine));
                    } else {
                        $replacements[$raw] = $raw . ' ❌ (Non trouvé - Référence potentiellement inventée)';
                    }
                }
            } else {
                // Référence non vérifiée (hallucination)
                if ($ref['type'] === 'text') {
                    $cleanLine = $ref['query'];
                    $startPos = strpos($raw, $cleanLine);
                    if ($startPos !== false) {
                        $replacements[$raw] = substr($raw, 0, $startPos) . $cleanLine . ' ❌ (Non trouvé - Référence potentiellement inventée)' . substr($raw, $startPos + strlen($cleanLine));
                    } else {
                        $replacements[$raw] = $raw . ' ❌ (Non trouvé - Référence potentiellement inventée)';
                    }
                } else {
                    $replacements[$raw] = $raw . ' ❌ (Non trouvé - Référence potentiellement inventée)';
                }
            }
        }

        // Appliquer tous les remplacements dans le texte d'origine.
        // Trier les clés par longueur descendante pour éviter de casser des sous-chaînes imbriquées.
        uksort($replacements, fn($a, $b) => strlen($b) <=> strlen($a));

        $modifiedText = $text;
        foreach ($replacements as $search => $replace) {
            $modifiedText = str_replace($search, $replace, $modifiedText);
        }

        return $modifiedText;
    }

    /**
     * Formate et enrichit les références d'une réponse au format HTML spécifié.
     *
     * @param string $text Le texte Markdown/DeepSeek original
     * @return string Le texte Markdown/HTML enrichi
     */
    public function formatEnrichedResponse(string $text): string
    {
        $extractedRefs = $this->extractor->extract($text);
        if (empty($extractedRefs)) {
            return $text;
        }

        $replacements = [];

        foreach ($extractedRefs as $ref) {
            $verification = $this->corrector->verify($ref);

            $raw = $ref['raw'];
            $url = $verification['url'];
            $verified = $verification['verified'];
            $corrected = $verification['corrected'];
            $extractedDoi = $verification['doi'];
            $metadata = $verification['corrected_metadata'];

            $authors = $metadata['author'] ?: 'Auteur inconnu';
            $year = $metadata['year'] ?: 'Année inconnue';
            $title = $metadata['title'] ?: ($ref['parsed']['title'] ?: $ref['query']);
            $journal = $metadata['journal'] ?: 'Document';

            // Clean title from trailing/leading quotes
            $title = trim($title, '"\'“”');

            if ($verified) {
                $targetUrl = $url;
                if ($extractedDoi) {
                    $targetUrl = 'https://doi.org/' . $extractedDoi;
                }

                $anchorText = '[Lien]';
                if ($extractedDoi) {
                    $anchorText = '[DOI]';
                } elseif (str_contains(strtolower($targetUrl), 'arxiv.org')) {
                    $anchorText = '[arXiv]';
                }

                $class = $corrected ? 'ref corrected' : 'ref verified';
                $badgeText = $corrected ? '🔄 corrigé' : '✅ vérifié';

                $html = '<span class="' . $class . '">' . "\n" .
                        '  <span class="ref-authors">' . htmlspecialchars($authors, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span> ' . "\n" .
                        '  (<span class="ref-year">' . htmlspecialchars($year, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>) ' . "\n" .
                        '  <span class="ref-title">"' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"</span>. ' . "\n" .
                        '  <span class="ref-journal">' . htmlspecialchars($journal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' . "\n";
                if ($targetUrl) {
                    $html .= '  <a href="' . htmlspecialchars($targetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="ref-link" target="_blank">' . htmlspecialchars($anchorText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>' . "\n";
                }
                $html .= '  <span class="ref-badge">' . htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' . "\n" .
                         '</span>';

                $replacements[$raw] = $html;
            } else {
                $class = 'ref unverified';
                $badgeText = '❌ non trouvé';

                $html = '<span class="' . $class . '">' . "\n" .
                        '  <span class="ref-authors">' . htmlspecialchars($authors, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span> ' . "\n" .
                        '  (<span class="ref-year">' . htmlspecialchars($year, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>) ' . "\n" .
                        '  <span class="ref-title">"' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"</span>. ' . "\n" .
                        '  <span class="ref-journal">' . htmlspecialchars($journal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' . "\n" .
                        '  <span class="ref-badge">' . htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' . "\n" .
                        '</span>';

                $replacements[$raw] = $html;
            }
        }

        // Appliquer tous les remplacements dans le texte d'origine.
        // Trier les clés par longueur descendante pour éviter de casser des sous-chaînes imbriquées.
        uksort($replacements, fn($a, $b) => strlen($b) <=> strlen($a));

        $modifiedText = $text;
        foreach ($replacements as $search => $replace) {
            $modifiedText = str_replace($search, $replace, $modifiedText);
        }

        return $modifiedText;
    }
}
