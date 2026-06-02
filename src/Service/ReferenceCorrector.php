<?php

namespace App\Service;

use App\Service\Search\OpenSerpSearchService;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ReferenceCorrector
{
    private const CACHE_TTL = 604800; // 7 jours en secondes
    private const MIN_VERIFY_SCORE = 60.0; // Seuil minimum de confiance : 60 points

    public function __construct(
        private OpenSerpSearchService $openSerpSearchService,
        private CacheInterface $cache,
        private HttpClientInterface $httpClient
    ) {
    }

    /**
     * Vérifie l'existence d'une référence, calcule le meilleur score de similarité
     * selon les règles de scoring avancées, extrait le DOI et gère les alternatives.
     *
     * @param array{type: string, raw: string, query: string, parsed: array} $ref
     * @return array{verified: bool, corrected: bool, title: string|null, url: string|null, doi: string|null, score: float, alternative_matches: array, corrected_metadata: array}
     */
    public function verify(array $ref): array
    {
        $cacheKey = 'ref_verify_v4_' . md5($ref['type'] . '_' . $ref['query']);

        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($ref) {
                $item->expiresAfter(self::CACHE_TTL);

                // Rechercher sur Google sans filtre de domaine
                $results = $this->openSerpSearchService->search(
                    $ref['query'],
                    domain: null,
                    limit: 3,
                    engine: 'google',
                    strict: false
                );

                if (empty($results)) {
                    return [
                        'verified'            => false,
                        'corrected'           => false,
                        'title'               => null,
                        'url'                 => null,
                        'doi'                 => null,
                        'score'               => 0.0,
                        'alternative_matches' => [],
                        'corrected_metadata'  => [
                            'author'  => $ref['parsed']['author'] ?: 'Auteur inconnu',
                            'year'    => $ref['parsed']['year'] ?: 'Année inconnue',
                            'title'   => $ref['parsed']['title'] ?: $ref['query'],
                            'journal' => $ref['parsed']['journal'] ?: 'Document',
                        ],
                    ];
                }

                $scoredResults = [];

                foreach ($results as $res) {
                    $score = $this->calculateSimilarityScore($ref, $res);
                    $scoredResults[] = [
                        'result' => $res,
                        'score'  => $score,
                    ];
                }

                // Trier les résultats par score décroissant
                usort($scoredResults, fn($a, $b) => $b['score'] <=> $a['score']);

                $bestMatch = $scoredResults[0];
                $bestScore = $bestMatch['score'];
                $bestResult = $bestMatch['result'];

                // Vérifier si le meilleur résultat dépasse le seuil minimum de confiance de 60 points
                $verified = ($bestScore >= self::MIN_VERIFY_SCORE);
                $corrected = false;
                $extractedDoi = null;
                $alternativeMatches = [];
                $correctedMetadata = [
                    'author'  => $ref['parsed']['author'] ?: 'Auteur inconnu',
                    'year'    => $ref['parsed']['year'] ?: 'Année inconnue',
                    'title'   => $ref['parsed']['title'] ?: $ref['query'],
                    'journal' => $ref['parsed']['journal'] ?: 'Document',
                ];

                if ($verified) {
                    // Récupérer les alternatives valides (score >= 60) excluant le premier
                    for ($i = 1; $i < count($scoredResults); $i++) {
                        if ($scoredResults[$i]['score'] >= self::MIN_VERIFY_SCORE) {
                            $alternativeMatches[] = [
                                'title' => $scoredResults[$i]['result']['title'],
                                'url'   => $scoredResults[$i]['result']['url'],
                                'score' => $scoredResults[$i]['score'],
                            ];
                        }
                    }

                    // Tenter d'extraire le vrai DOI du meilleur résultat
                    $extractedDoi = $this->extractDoiFromString($bestResult['url'] . ' ' . $bestResult['description']);

                    // Extraire les métadonnées réelles du snippet
                    $snippetData = $this->parseSnippet($bestResult['description'], $bestResult['title'], $bestResult['url']);

                    if ($extractedDoi) {
                        $doiMeta = $this->resolveDoiMetadata($extractedDoi);
                        if ($doiMeta) {
                            if (!empty($doiMeta['authors'])) {
                                $snippetData['author'] = $doiMeta['authors'];
                            }
                            if (!empty($doiMeta['year'])) {
                                $snippetData['year'] = (string) $doiMeta['year'];
                            }
                            if (!empty($doiMeta['journal'])) {
                                $snippetData['journal'] = $doiMeta['journal'];
                            }
                            if (!empty($doiMeta['title'])) {
                                $bestResult['title'] = $doiMeta['title'];
                            }
                        }
                    }

                    $correctedMetadata = [
                        'author'  => $snippetData['author'] ?: ($ref['parsed']['author'] ?: 'Auteur inconnu'),
                        'year'    => $snippetData['year'] ?: ($ref['parsed']['year'] ?: 'Année inconnue'),
                        'title'   => $bestResult['title'] ?: ($ref['parsed']['title'] ?: $ref['query']),
                        'journal' => $snippetData['journal'] ?: ($ref['parsed']['journal'] ?: 'Document'),
                    ];

                    if ($ref['type'] === 'text') {
                        // Comparer la ressemblance du titre
                        $cleanQuery = strtolower(preg_replace('/[^a-z0-9]/i', '', $ref['parsed']['title'] ?? ''));
                        $cleanResultTitle = strtolower(preg_replace('/[^a-z0-9]/i', $bestResult['title'] ?? ''));
                        similar_text($cleanQuery, $cleanResultTitle, $percent);
                        
                        // Si le titre trouvé est différent, on signale une correction
                        if ($percent < 60) {
                            $corrected = true;
                        }
                    }

                    // Si on a extrait un vrai DOI non présent à l'origine
                    if ($extractedDoi && !str_contains(strtolower($ref['raw']), strtolower($extractedDoi))) {
                        $corrected = true;
                    }

                    // Si l'auteur, l'année ou le journal a changé par rapport à l'original (et que l'original n'était pas vide)
                    if (($ref['parsed']['author'] && strtolower($correctedMetadata['author']) !== strtolower($ref['parsed']['author'])) ||
                        ($ref['parsed']['year'] && strtolower($correctedMetadata['year']) !== strtolower($ref['parsed']['year'])) ||
                        ($ref['parsed']['journal'] && strtolower($correctedMetadata['journal']) !== strtolower($ref['parsed']['journal']))) {
                        $corrected = true;
                    }
                }

                return [
                    'verified'            => $verified,
                    'corrected'           => $corrected,
                    'title'               => $verified ? $bestResult['title'] : null,
                    'url'                 => $verified ? $bestResult['url'] : null,
                    'doi'                 => $extractedDoi,
                    'score'               => $bestScore,
                    'alternative_matches' => $alternativeMatches,
                    'corrected_metadata'  => $correctedMetadata,
                ];
            });
        } catch (\Exception $e) {
            return [
                'verified'            => false,
                'corrected'           => false,
                'title'               => null,
                'url'                 => null,
                'doi'                 => null,
                'score'               => 0.0,
                'alternative_matches' => [],
                'corrected_metadata'  => [
                    'author'  => $ref['parsed']['author'] ?: 'Auteur inconnu',
                    'year'    => $ref['parsed']['year'] ?: 'Année inconnue',
                    'title'   => $ref['parsed']['title'] ?: $ref['query'],
                    'journal' => $ref['parsed']['journal'] ?: 'Document',
                ],
            ];
        }
    }

    /**
     * Calcule le score de similitude global selon la charte avancée.
     */
    private function calculateSimilarityScore(array $ref, array $result): float
    {
        $score = 0.0;

        // 1. Correspondance exacte du DOI (si fourni) -> +100 points
        $citationDoi = $this->extractDoiFromString($ref['raw']);
        $resultDoi = $this->extractDoiFromString($result['url'] . ' ' . $result['description']);
        if ($citationDoi && $resultDoi && strtolower($citationDoi) === strtolower($resultDoi)) {
            $score += 100.0;
        }

        // 2. Correspondance exacte de l'arXiv ID -> +90 points
        if ($ref['type'] === 'arxiv') {
            $cleanArxiv = strtolower(str_replace('arxiv:', '', $ref['query']));
            if (str_contains(strtolower($result['url']), $cleanArxiv) || 
                str_contains(strtolower($result['title']), $cleanArxiv)) {
                $score += 90.0;
            }
        }

        // 3. Similarité du titre (Levenshtein ratio) -> max 50 points
        $refTitle = strtolower(preg_replace('/[^a-z0-9]/i', '', $ref['parsed']['title'] ?? ''));
        $resTitle = strtolower(preg_replace('/[^a-z0-9]/i', $result['title'] ?? ''));
        
        if (!empty($refTitle) && !empty($resTitle)) {
            // Tronquer pour éviter le dépassement de la limite de 255 caractères de levenshtein()
            $refTitleTrunc = substr($refTitle, 0, 255);
            $resTitleTrunc = substr($resTitle, 0, 255);
            
            $maxLength = max(strlen($refTitleTrunc), strlen($resTitleTrunc));
            if ($maxLength > 0) {
                $distance = levenshtein($refTitleTrunc, $resTitleTrunc);
                $ratio = ($maxLength - $distance) / $maxLength;
                $score += max(0.0, $ratio * 50.0);
            } else {
                $score += 50.0;
            }
        }

        // 4. Correspondance du premier auteur -> +30 points
        if (!empty($ref['parsed']['author'])) {
            $authorParts = explode(',', strtolower($ref['parsed']['author']));
            $lastName = trim(explode(' ', trim($authorParts[0]))[0]);
            
            if (!empty($lastName)) {
                $inTitle = str_contains(strtolower($result['title']), $lastName);
                $inDesc = str_contains(strtolower($result['description']), $lastName);
                if ($inTitle || $inDesc) {
                    $score += 30.0;
                }
            }
        }

        // 5. Correspondance de l'année à ±1 an -> +10 points
        if (!empty($ref['parsed']['year'])) {
            $refYear = (int) $ref['parsed']['year'];
            $resYear = $this->extractYearFromString($result['title'] . ' ' . $result['description']);
            if ($resYear && abs($refYear - $resYear) <= 1) {
                $score += 10.0;
            }
        }

        return $score;
    }

    /**
     * Tente d'extraire un DOI (format 10.xxxx/xxxx) depuis une chaîne de caractères.
     */
    public function extractDoiFromString(string $str): ?string
    {
        if (preg_match('#\b(10\.\d{4,9}/[-._;()/:A-Za-z0-9]+)\b#', $str, $matches)) {
            return rtrim($matches[1], '.,;)');
        }
        return null;
    }

    /**
     * Extrait une année à 4 chiffres (1900-2099) depuis une chaîne.
     */
    public function extractYearFromString(string $str): ?int
    {
        if (preg_match('/\b(19\d{2}|20\d{2})\b/', $str, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Parse un snippet Google pour en extraire l'auteur, l'année et le journal.
     */
    public function parseSnippet(string $description, string $title, string $url): array
    {
        $author = null;
        $year = null;
        $journal = null;

        // Le format typique de Google Scholar / Google Search pour les snippets académiques :
        // "Auteurs - Journal, Année - domaine.com" ou "Auteurs - Année - domaine.com"
        // Exemple : "M Sall, K Diallo - arXiv preprint arXiv:2401.12345, 2024 - arxiv.org"
        // On sépare par " - " (tiret avec espaces) ou " – " (en-dash de Google)
        $parts = preg_split('/\s+[-–]\s+/', $description);
        if (count($parts) >= 2) {
            $authorsPart = trim($parts[0]);
            // Nettoyage des points de suspension et balises
            $authorsPart = preg_replace('/\.\.\.$/', '', $authorsPart);
            $authorsPart = strip_tags($authorsPart);
            if (!empty($authorsPart) && strlen($authorsPart) < 100 && !preg_match('/^(in|by|from|the|this|a|an)\b/i', $authorsPart)) {
                $author = $authorsPart;
            }

            $journalYearPart = trim($parts[1]);
            // Trouver l'année dans cette partie
            if (preg_match('/\b(19\d{2}|20\d{2})\b/', $journalYearPart, $yearMatches)) {
                $year = $yearMatches[1];
            }

            // Le reste de cette partie est souvent le journal
            $journalClean = trim(preg_replace('/\b(19\d{2}|20\d{2})\b/', '', $journalYearPart));
            $journalClean = trim(rtrim($journalClean, ',. '));
            if (!empty($journalClean) && strlen($journalClean) < 100) {
                $journal = $journalClean;
            }
        }

        // Fallbacks
        if (!$year && preg_match('/\b(19\d{2}|20\d{2})\b/', $description, $yearMatches)) {
            $year = $yearMatches[1];
        }
        if (!$year && preg_match('/\b(19\d{2}|20\d{2})\b/', $title, $yearMatches)) {
            $year = $yearMatches[1];
        }

        // Si le domaine est connu, affiner le journal
        $lowUrl = strtolower($url);
        if (str_contains($lowUrl, 'arxiv.org')) {
            $journal = 'Arxiv';
        } elseif (str_contains($lowUrl, 'nature.com')) {
            $journal = 'Nature';
        } elseif (str_contains($lowUrl, 'science.org')) {
            $journal = 'Science';
        } elseif (str_contains($lowUrl, 'pubmed.ncbi.nlm.nih.gov')) {
            $journal = 'PubMed';
        } elseif (str_contains($lowUrl, 'ieeexplore.ieee.org')) {
            $journal = 'IEEE';
        }

        return [
            'author'  => $author,
            'year'    => $year,
            'journal' => $journal,
        ];
    }

    /**
     * Résout les métadonnées officielles d'un DOI auprès de l'API Crossref (avec cache).
     *
     * @param string $doi
     * @return array{authors: string|null, year: int|null, journal: string|null, title: string|null} | null
     */
    public function resolveDoiMetadata(string $doi): ?array
    {
        $cleanDoi = trim(strtolower($doi));
        if (empty($cleanDoi)) {
            return null;
        }

        $cacheKey = 'doi_meta_' . md5($cleanDoi);

        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($cleanDoi) {
                $item->expiresAfter(self::CACHE_TTL); // 7 jours

                $url = 'https://api.crossref.org/works/' . urlencode($cleanDoi);
                
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => [
                        'User-Agent' => 'DjolibaSearch/2.0 (mailto:admin@djoliba.local)',
                    ],
                    'timeout' => 5,
                ]);

                if ($response->getStatusCode() !== 200) {
                    return null;
                }

                $data = $response->toArray();
                $message = $data['message'] ?? [];
                if (empty($message)) {
                    return null;
                }

                // 1. Extraire les auteurs
                $authorsList = [];
                if (!empty($message['author'])) {
                    foreach ($message['author'] as $author) {
                        $nameParts = [];
                        if (!empty($author['given'])) {
                            $nameParts[] = $author['given'];
                        }
                        if (!empty($author['family'])) {
                            $nameParts[] = $author['family'];
                        }
                        if (!empty($nameParts)) {
                            $authorsList[] = implode(' ', $nameParts);
                        }
                    }
                }
                $authorsStr = !empty($authorsList) ? implode(', ', $authorsList) : null;

                // 2. Extraire l'année
                $year = null;
                if (!empty($message['published-print']['date-parts'][0][0])) {
                    $year = (int) $message['published-print']['date-parts'][0][0];
                } elseif (!empty($message['published-online']['date-parts'][0][0])) {
                    $year = (int) $message['published-online']['date-parts'][0][0];
                } elseif (!empty($message['created']['date-parts'][0][0])) {
                    $year = (int) $message['created']['date-parts'][0][0];
                }

                // 3. Extraire le journal
                $journal = null;
                if (!empty($message['container-title'][0])) {
                    $journal = $message['container-title'][0];
                }

                // 4. Extraire le titre
                $title = null;
                if (!empty($message['title'][0])) {
                    $title = $message['title'][0];
                }

                return [
                    'authors' => $authorsStr,
                    'year'    => $year,
                    'journal' => $journal,
                    'title'   => $title,
                ];
            });
        } catch (\Exception $e) {
            return null;
        }
    }
}
