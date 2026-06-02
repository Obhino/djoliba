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

        // 3. Extraire les lignes classiques de la bibliographie
        $lines = explode("\n", $text);
        $inBibliography = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }

            // Détecter si on entre dans la section bibliographie
            if (preg_match('/^(?:#+|\*\*)\s*(?:Bibliographie|Références|References|Sources)\b/i', $trimmed)) {
                $inBibliography = true;
                continue;
            }

            if ($inBibliography) {
                // Si on rencontre un nouveau titre principal de section, on s'arrête
                if (str_starts_with($trimmed, '#') && !str_starts_with($trimmed, '###') && !str_starts_with($trimmed, '####')) {
                    $inBibliography = false;
                    continue;
                }

                // Nettoyer les puces markdown (- *, 1. etc.)
                $cleanLine = preg_replace('/^(?:[\-\*\+\s\d]+[\.\)]?\s*)+/', '', $trimmed);
                $cleanLine = trim($cleanLine);

                // Une référence textuelle valide doit faire une certaine longueur et ne pas être un séparateur
                if (strlen($cleanLine) > 25 && !str_starts_with($cleanLine, '---')) {
                    // Éviter les doublons si un DOI ou arXiv déjà extrait fait partie de cette ligne
                    $alreadyCovered = false;
                    foreach ($references as $ref) {
                        if (str_contains($cleanLine, $ref['raw'])) {
                            $alreadyCovered = true;
                            break;
                        }
                    }

                    if (!$alreadyCovered) {
                        $parsed = $this->parseTextReference($cleanLine);
                        $references[] = [
                            'type'   => 'text',
                            'raw'    => $trimmed, // Ligne complète d'origine
                            'query'  => $cleanLine, // Version nettoyée pour la recherche
                            'parsed' => $parsed,
                        ];
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Extrait les métadonnées d'une référence textuelle (Auteur, Année, Titre, Journal).
     */
    private function parseTextReference(string $cleanLine): array
    {
        $author = '';
        $year = '';
        $title = $cleanLine;
        $journal = '';

        // Extraire l'année (4 chiffres entre parenthèses)
        if (preg_match('/\((\d{4})\)/', $cleanLine, $yearMatches)) {
            $year = $yearMatches[1];
            $parts = explode($yearMatches[0], $cleanLine, 2);
            if (count($parts) === 2) {
                $author = trim(rtrim(trim($parts[0]), ',.'));
                $remaining = trim($parts[1]);

                // Extraire le titre et le journal
                if (preg_match('/["“]([^"”]+)["”]/', $remaining, $titleMatches)) {
                    $title = $titleMatches[1];
                    $titlePos = strpos($remaining, $titleMatches[0]);
                    $journal = trim(ltrim(substr($remaining, $titlePos + strlen($titleMatches[0])), '., '));
                } else {
                    $titleParts = explode('.', $remaining);
                    $title = trim($titleParts[0]);
                    $journal = isset($titleParts[1]) ? trim(implode('.', array_slice($titleParts, 1))) : '';
                }
            }
        }

        // Nettoyer la ponctuation de fin sur le journal
        $journal = rtrim(trim($journal), '.,');

        return [
            'author'  => $author ?: 'Auteur inconnu',
            'year'    => $year ?: 'Année inconnue',
            'title'   => $title ?: 'Titre inconnu',
            'journal' => $journal ?: 'Document',
        ];
    }
}
