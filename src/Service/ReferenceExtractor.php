<?php

namespace App\Service;

class ReferenceExtractor
{
    /**
     * Extrait les références potentielles d'un texte Markdown.
     *
     * @param string $text Le texte généré par l'IA
     * @return array Tableau de références [{type, raw, query, parsed: {author, year, title, journal}}]
     */
    public function extract(string $text): array
    {
        $references = [];

        // 1. Extraire les DOIs (10.xxxx/xxxx)
        preg_match_all('#\b10\.\d{4,9}/[-._;()/:A-Za-z0-9]+\b#', $text, $doiMatches);
        foreach (array_unique($doiMatches[0]) as $doi) {
            $references[] = [
                'type'   => 'doi',
                'raw'    => $doi,
                'query'  => $doi,
                'parsed' => [
                    'author'  => null,
                    'year'    => null,
                    'title'   => $doi,
                    'journal' => null,
                ]
            ];
        }

        // 2. Extraire les arXiv IDs (arXiv:YYMM.NNNNN)
        preg_match_all('/\barXiv:\d{4}\.\d{4,5}(?:v\d+)?\b/i', $text, $arxivMatches);
        foreach (array_unique($arxivMatches[0]) as $arxiv) {
            $references[] = [
                'type'   => 'arxiv',
                'raw'    => $arxiv,
                'query'  => $arxiv,
                'parsed' => [
                    'author'  => null,
                    'year'    => null,
                    'title'   => $arxiv,
                    'journal' => null,
                ]
            ];
        }

        return $references;
    }
}
