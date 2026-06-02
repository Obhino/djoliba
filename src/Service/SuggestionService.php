<?php

namespace App\Service;

use App\Service\IA\CacheService;
use App\Service\IA\DeepSeekService;
use App\Service\Search\OpenSerpSearchService;
use App\Service\ReferenceCorrector;

class SuggestionService
{
    public function __construct(
        private OpenSerpSearchService $openSerpSearchService,
        private CacheService $cacheService,
        private ReferenceCorrector $referenceCorrector,
        private DeepSeekService $deepSeekService,
    ) {
    }

    /**
     * Suggère des articles scientifiques réels complémentaires à une requête.
     * Génère des mots-clés en anglais via DeepSeek pour optimiser la recherche,
     * puis fait une recherche réelle via l'API OpenSERP.
     *
     * @param string $query La requête ou le sujet de recherche.
     * @param int    $limit Nombre d'articles à suggérer (défaut: 5).
     * @return array<int, array{title: string, authors: string, year: int, abstract: string, doi: string, verified: bool, url: string|null, journal: string}>
     */
    public function suggest(string $query, int $limit = 5): array
    {
        $cacheKey = 'suggestions_v5_' . md5($query . '_' . $limit);

        return $this->cacheService->remember(
            $cacheKey,
            function () use ($query, $limit) {
                // 1. Traduire/Générer des mots-clés en anglais via DeepSeek pour maximiser les résultats scientifiques
                $searchQuery = $query;
                try {
                    $prompt = sprintf(
                        'Tu es un expert en recherche bibliographique scientifique. Génère uniquement une liste de 3 à 5 mots-clés ou termes de recherche en anglais (sans ponctuation, séparés uniquement par des espaces) correspondant au sujet suivant : "%s". Réponds UNIQUEMENT avec les mots-clés en anglais, aucun autre mot.',
                        $query
                    );

                    $rawKeywords = $this->deepSeekService->call($prompt, [
                        'temperature' => 0.1,
                        'max_tokens' => 50,
                    ]);

                    $cleanKeywords = trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $rawKeywords));
                    if (!empty($cleanKeywords)) {
                        $searchQuery = $cleanKeywords;
                    }
                } catch (\Exception $e) {
                    // Fallback sur la requête d'origine en cas d'erreur de l'IA
                }

                // 2. Effectuer une recherche simple et ultra-rapide (strict: false) en demandant 15 résultats
                // On utilise le moteur de recherche 'duck' (DuckDuckGo) au lieu de 'google' pour éviter les lenteurs
                // de scraping et obtenir une réponse instantanée.
                $searchResults = $this->openSerpSearchService->search(
                    $searchQuery,
                    domain: null,
                    limit: 15,
                    engine: 'duck',
                    strict: false
                );

                if (empty($searchResults)) {
                    return [];
                }

                $academicDomains = [
                    'arxiv.org', 'hal.science', 'pubmed.ncbi.nlm.nih.gov', 'ncbi.nlm.nih.gov',
                    'scholar.google', 'ieeexplore.ieee.org', 'nature.com', 'science.org',
                    'researchgate.net', 'springer.com', 'sciencedirect.com', 'wiley.com',
                    'mdpi.com', 'plos.org', 'frontiersin.org', 'acm.org', 'academic.oup.com',
                    'royalsocietypublishing.org', 'cambridge.org', 'jstor.org'
                ];

                $academicArticles = [];
                $otherArticles = [];

                foreach ($searchResults as $res) {
                    if (empty($res['url'])) {
                        continue;
                    }

                    // Tenter d'extraire le DOI depuis l'URL ou la description
                    $doi = $this->referenceCorrector->extractDoiFromString($res['url'] . ' ' . $res['description']);

                    // Tenter d'extraire l'auteur, l'année et le journal depuis le snippet
                    $snippetData = $this->referenceCorrector->parseSnippet($res['description'], $res['title'], $res['url']);

                    // Nettoyer le titre (enlever les suffixes de sites ou préfixes comme [PDF])
                    $cleanTitle = $res['title'];
                    $cleanTitle = preg_replace('/\s+-\s+(arXiv|HAL|PubMed|Nature|Science|Google Scholar|IEEE|ResearchGate|Springer|Wiley|MDPI|Frontiers|JSTOR).*$/i', '', $cleanTitle);
                    $cleanTitle = preg_replace('/^\[PDF\]\s+/i', '', $cleanTitle);
                    $cleanTitle = trim($cleanTitle, " \t\n\r\0\x0B\"'“”.");

                    // Extraire l'année depuis le snippet ou le titre
                    $year = (int) ($snippetData['year'] ?: $this->referenceCorrector->extractYearFromString($res['title'] . ' ' . $res['description']) ?: 0);

                    // Si un DOI est présent, on enrichit les métadonnées via Crossref
                    if ($doi) {
                        $doiMeta = $this->referenceCorrector->resolveDoiMetadata($doi);
                        if ($doiMeta) {
                            if (!empty($doiMeta['authors'])) {
                                $snippetData['author'] = $doiMeta['authors'];
                            }
                            if (!empty($doiMeta['year'])) {
                                $year = $doiMeta['year'];
                            }
                            if (!empty($doiMeta['journal'])) {
                                $snippetData['journal'] = $doiMeta['journal'];
                            }
                            if (!empty($doiMeta['title'])) {
                                $cleanTitle = $doiMeta['title'];
                            }
                        }
                    }

                    // Formater le snippet pour l'abstract
                    $abstract = trim($res['description']);
                    $abstract = preg_replace('/\s*\.\.\.\s*$/', '...', $abstract);

                    $article = [
                        'title'    => $cleanTitle ?: 'Article sans titre',
                        'authors'  => $snippetData['author'] ?: 'Auteurs inconnus',
                        'year'     => $year,
                        'abstract' => $abstract ?: 'Résumé non disponible.',
                        'doi'      => $doi ?: '',
                        'verified' => true, // C'est un vrai article issu de la recherche scientifique réelle !
                        'url'      => $res['url'],
                        'journal'  => $snippetData['journal'] ?: 'Publication',
                    ];

                    // Classer par pertinence académique selon l'URL
                    $isAcademic = false;
                    $lowUrl = strtolower($res['url']);
                    foreach ($academicDomains as $domain) {
                        if (str_contains($lowUrl, $domain)) {
                            $isAcademic = true;
                            // Affiner le nom du journal si besoin
                            if (!$article['journal'] || $article['journal'] === 'Publication') {
                                if (str_contains($domain, 'arxiv.org')) $article['journal'] = 'arXiv';
                                elseif (str_contains($domain, 'hal.science')) $article['journal'] = 'HAL';
                                elseif (str_contains($domain, 'pubmed') || str_contains($domain, 'ncbi')) $article['journal'] = 'PubMed';
                                elseif (str_contains($domain, 'nature.com')) $article['journal'] = 'Nature';
                                elseif (str_contains($domain, 'science.org')) $article['journal'] = 'Science';
                                elseif (str_contains($domain, 'ieeexplore')) $article['journal'] = 'IEEE';
                                elseif (str_contains($domain, 'researchgate')) $article['journal'] = 'ResearchGate';
                                elseif (str_contains($domain, 'springer')) $article['journal'] = 'Springer';
                                elseif (str_contains($domain, 'sciencedirect')) $article['journal'] = 'ScienceDirect';
                            }
                            break;
                        }
                    }

                    if ($isAcademic) {
                        $academicArticles[] = $article;
                    } else {
                        $otherArticles[] = $article;
                    }
                }

                // Combiner en priorisant les articles académiques et limiter au nombre demandé
                $combined = array_merge($academicArticles, $otherArticles);
                return array_slice($combined, 0, $limit);
            },
            3600 // 1 heure
        );
    }
}
