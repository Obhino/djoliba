<?php

namespace App\Service\Search;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenSerpSearchService
{
    private const CACHE_TTL = 3600; // 1 heure

    private const SCIENTIFIC_DOMAINS = [
        'arxiv.org',
        'hal.science',
        'pubmed.ncbi.nlm.nih.gov',
        'scholar.google.com',
        'ieeexplore.ieee.org',
        'nature.com',
        'science.org',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private CacheInterface $cache,
        #[Autowire('%env(OPENSERP_URL)%')]
        private string $openserpUrl,
    ) {
    }

    /**
     * Effectue une recherche web via l'API OpenSERP.
     * Les résultats sont mis en cache localement pour 1h.
     *
     * @param string $query Terme recherché
     * @param string|null $domain Filtrer par un domaine scientifique spécifique (ex: 'arxiv.org')
     * @param int $limit Limite de résultats (défaut 10)
     * @param string $engine Moteur de recherche ('google', 'duck', ou 'mega')
     * @param bool $strict Si true, filtre uniquement sur les domaines scientifiques (globaux ou spécifique)
     * @return array Tableau des résultats formatés [{title, url, description, source}]
     */
    public function search(
        string $query,
        ?string $domain = null,
        int $limit = 10,
        string $engine = 'google',
        bool $strict = true
    ): array {
        // 1. Gérer le filtrage par domaine scientifique
        $finalQuery = trim($query);

        if ($domain) {
            $domainMapping = [
                'arxiv' => 'arxiv.org',
                'hal' => 'hal.science',
                'pubmed' => 'pubmed.ncbi.nlm.nih.gov',
                'scholar' => 'scholar.google.com',
                'ieee' => 'ieeexplore.ieee.org',
                'nature' => 'nature.com',
                'science' => 'science.org',
            ];
            
            $cleanDomain = $domainMapping[strtolower($domain)] ?? $domain;
            $finalQuery .= ' site:' . $cleanDomain;
        } elseif ($strict) {
            // Restreindre la recherche uniquement aux domaines scientifiques configurés par défaut
            $siteFilters = array_map(fn($d) => 'site:' . $d, self::SCIENTIFIC_DOMAINS);
            $finalQuery .= ' (' . implode(' OR ', $siteFilters) . ')';
        }

        // 2. Définir le moteur de recherche
        $allowedEngines = ['google', 'duck', 'mega'];
        $cleanEngine = in_array(strtolower($engine), $allowedEngines, true) ? strtolower($engine) : 'duck';
        
        // Anti-Captcha OVH : On force DuckDuckGo car Google bloque les IP serveurs
        if ($cleanEngine === 'google') {
            $cleanEngine = 'duck';
        }

        // 3. Calculer une clé de cache Redis unique
        $cacheKey = 'openserp_' . md5($finalQuery . '_' . $limit . '_' . $cleanEngine);

        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($finalQuery, $limit, $cleanEngine) {
                $item->expiresAfter(self::CACHE_TTL);

                $baseUrl = $this->openserpUrl;
                if (str_starts_with($baseUrl, 'tcp://')) {
                    $baseUrl = 'http://' . substr($baseUrl, 6);
                }

                // Construire l'URL d'appel
                $url = sprintf(
                    '%s/%s/search',
                    rtrim($baseUrl, '/'),
                    $cleanEngine
                );

                $response = $this->httpClient->request('GET', $url, [
                    'query' => [
                        'text' => $finalQuery,
                        'limit' => $limit,
                    ],
                    'timeout' => 5,
                ]);

                if ($response->getStatusCode() !== 200) {
                    $this->logger->error(sprintf('[OpenSerp] HTTP Error %d calling OpenSERP: %s', $response->getStatusCode(), $response->getContent(false)));
                    return [];
                }

                $data = $response->toArray();
                
                // Formater les résultats
                $results = [];
                $rawResults = $data['results'] ?? [];

                foreach ($rawResults as $item) {
                    $results[] = [
                        'title' => (string) ($item['title'] ?? 'Sans titre'),
                        'url' => (string) ($item['url'] ?? ''),
                        'description' => (string) ($item['snippet'] ?? ''),
                        'source' => (string) ($item['engine'] ?? $cleanEngine),
                    ];
                }

                return $results;
            });
        } catch (\Exception $e) {
            $this->logger->error('[OpenSerp] Exception during search: ' . $e->getMessage(), [
                'exception' => $e,
                'query' => $finalQuery,
            ]);

            return [];
        }
    }
}
