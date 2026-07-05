<?php

namespace App\Service\Bibliography;

use App\Entity\BibliographicReference;
use App\Entity\User;
use App\Repository\BibliographicReferenceRepository;
use App\Service\Converter\LatexConverter;

class BibliographyExporter
{
    public function __construct(
        private BibliographicReferenceRepository $referenceRepository,
        private LatexConverter $latexConverter
    ) {
    }

    /**
     * Extrait les clés de citation uniques du contenu textuel.
     * Gère le format HTML (<cite data-cite-key="key1,key2">)
     * et le format LaTeX (\cite{key1,key2}).
     */
    public function extractKeys(string $content): array
    {
        $keys = [];

        // 1. Extraction HTML (data-cite-key="...")
        preg_match_all('/data-cite-key="([^"]+)"/i', $content, $htmlMatches);
        foreach ($htmlMatches[1] as $match) {
            foreach (explode(',', $match) as $key) {
                $trimmed = trim($key);
                if ($trimmed !== '') {
                    $keys[] = $trimmed;
                }
            }
        }

        // 2. Extraction LaTeX (\cite{...})
        preg_match_all('/\\\\cite\{([^}]+)\}/i', $content, $latexMatches);
        foreach ($latexMatches[1] as $match) {
            foreach (explode(',', $match) as $key) {
                $trimmed = trim($key);
                if ($trimmed !== '') {
                    $keys[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Récupère les références correspondantes aux clés pour un utilisateur donné.
     *
     * @return BibliographicReference[]
     */
    public function getReferencesByKeys(User $user, array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        return $this->referenceRepository->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.citeKey IN (:keys)')
            ->setParameter('user', $user)
            ->setParameter('keys', $keys)
            ->getQuery()
            ->getResult();
    }

    /**
     * Génère une bibliographie au format HTML.
     *
     * @param BibliographicReference[] $references
     */
    public function generateHtml(array $references): string
    {
        if (empty($references)) {
            return '';
        }

        // Trier par auteur et année
        usort($references, function (BibliographicReference $a, BibliographicReference $b) {
            $authorA = $a->getAuthors() ?? '';
            $authorB = $b->getAuthors() ?? '';
            $cmp = strcasecmp($authorA, $authorB);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($a->getYear() ?? '') <=> ($b->getYear() ?? '');
        });

        $html = '<div class="bibliography-section mt-8 border-t border-slate-200 pt-6">';
        $html .= '<h2 class="text-lg font-bold text-djoliba mb-4">Bibliographie</h2>';
        $html .= '<ul class="space-y-3 list-none pl-0 text-xs text-slate-700">';

        foreach ($references as $ref) {
            $author = htmlspecialchars($ref->getAuthors() ?? 'Anonyme', ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars($ref->getTitle() ?? '', ENT_QUOTES, 'UTF-8');
            $year = htmlspecialchars($ref->getYear() ?? '', ENT_QUOTES, 'UTF-8');
            $journal = htmlspecialchars($ref->getJournal() ?? '', ENT_QUOTES, 'UTF-8');
            $doi = htmlspecialchars($ref->getDoi() ?? '', ENT_QUOTES, 'UTF-8');

            $html .= '<li class="pl-6 -indent-6">';
            $html .= sprintf('<strong class="font-semibold">[%s]</strong> %s.', htmlspecialchars($ref->getCiteKey(), ENT_QUOTES, 'UTF-8'), $author);
            if ($year) {
                $html .= ' (' . $year . ').';
            }
            if ($title) {
                $html .= ' <span class="italic">' . $title . '</span>.';
            }
            if ($journal) {
                $html .= ' ' . $journal . '.';
            }
            if ($doi) {
                $html .= sprintf(' DOI: <a href="https://doi.org/%s" target="_blank" class="text-blue-500 hover:underline">%s</a>.', $doi, $doi);
            }
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Génère une bibliographie au format LaTeX.
     *
     * @param BibliographicReference[] $references
     */
    public function generateLatex(array $references): string
    {
        if (empty($references)) {
            return '';
        }

        // Trier par auteur et année
        usort($references, function (BibliographicReference $a, BibliographicReference $b) {
            $authorA = $a->getAuthors() ?? '';
            $authorB = $b->getAuthors() ?? '';
            $cmp = strcasecmp($authorA, $authorB);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($a->getYear() ?? '') <=> ($b->getYear() ?? '');
        });

        $lines = [];
        $lines[] = '';
        $lines[] = '% ─── Bibliographie générée automatiquement par Djoliba ───';
        $lines[] = '\begin{thebibliography}{' . count($references) . '}';
        $lines[] = '';

        foreach ($references as $ref) {
            $citeKey = $ref->getCiteKey();
            $author  = $this->latexConverter->latexEscape($ref->getAuthors() ?? 'Anonyme');
            $title   = $this->latexConverter->latexEscape($ref->getTitle() ?? '');
            $year    = $this->latexConverter->latexEscape($ref->getYear() ?? '');
            $journal = $this->latexConverter->latexEscape($ref->getJournal() ?? '');
            $doi     = $ref->getDoi() ?? '';

            $lines[] = '\bibitem{' . $citeKey . '}';
            $lines[] = $author . '.';

            if ($title) {
                $lines[] = '\textit{' . $title . '}.';
            }

            // Source/Journal
            $source = [];
            if ($journal) $source[] = $journal;
            if ($year)    $source[] = '(' . $year . ')';

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
     * Génère un fichier .bib valide à partir d'un tableau de références.
     *
     * @param BibliographicReference[] $references
     */
    public function exportToBibtex(array $references): string
    {
        $bibtex = "";
        foreach ($references as $ref) {
            $type = $ref->getEntryType() ?: 'misc';
            $key = $ref->getCiteKey();
            
            $bibtex .= "@" . $type . "{" . $key . ",\n";
            
            $fields = [];
            if ($ref->getTitle()) {
                $fields['title'] = $ref->getTitle();
            }
            if ($ref->getAuthors()) {
                $fields['author'] = $ref->getAuthors();
            }
            if ($ref->getYear()) {
                $fields['year'] = $ref->getYear();
            }
            if ($ref->getJournal()) {
                if ($type === 'article') {
                    $fields['journal'] = $ref->getJournal();
                } elseif ($type === 'book' || $type === 'incollection') {
                    $fields['publisher'] = $ref->getJournal();
                } else {
                    $fields['booktitle'] = $ref->getJournal();
                }
            }
            if ($ref->getDoi()) {
                $fields['doi'] = $ref->getDoi();
            }

            // Exporter les autres champs du rawData
            $raw = $ref->getRawData() ?? [];
            foreach ($raw as $k => $v) {
                if (in_array(strtolower($k), ['title', 'author', 'year', 'journal', 'booktitle', 'publisher', 'doi', 'citekey', 'entrytype'])) {
                    continue;
                }
                if ($v !== null && $v !== '') {
                    $fields[strtolower($k)] = $v;
                }
            }

            $fieldLines = [];
            foreach ($fields as $fieldName => $fieldValue) {
                $fieldLines[] = "    " . $fieldName . " = {" . $fieldValue . "}";
            }

            $bibtex .= implode(",\n", $fieldLines) . "\n";
            $bibtex .= "}\n\n";
        }

        return rtrim($bibtex) . "\n";
    }
}
