<?php

namespace App\Service\Bibliography;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class DoiResolver
{
    public function __construct(
        private HttpClientInterface $httpClient
    ) {
    }

    /**
     * Résout un DOI en interrogeant l'API Crossref.
     *
     * @return array{title: ?string, authors: ?string, year: ?string, journal: ?string, volume: ?string, pages: ?string, publisher: ?string, doi: string}|null
     */
    public function resolve(string $doi): ?array
    {
        $cleanedDoi = $this->cleanDoi($doi);
        if (empty($cleanedDoi)) {
            return null;
        }

        try {
            // Requête vers Crossref
            $response = $this->httpClient->request(
                'GET',
                'https://api.crossref.org/works/' . urlencode($cleanedDoi),
                [
                    'headers' => [
                        'User-Agent' => 'DjolibaSearch/2.0 (https://djolibasearch.com; mailto:ulrichagre@outlook.fr)',
                    ],
                    'timeout' => 8.0,
                ]
            );

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->toArray();
            $message = $data['message'] ?? null;
            if (!$message) {
                return null;
            }

            return $this->normalizeMetadata($message, $cleanedDoi);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Nettoie le DOI en enlevant d'éventuels préfixes de domaine ou d'URL.
     */
    private function cleanDoi(string $doi): string
    {
        $doi = trim($doi);
        $prefixes = [
            'https://doi.org/',
            'http://doi.org/',
            'dx.doi.org/',
            'doi.org/',
            'doi:',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with(strtolower($doi), $prefix)) {
                $doi = substr($doi, strlen($prefix));
            }
        }

        return trim($doi);
    }

    /**
     * Normalise les métadonnées de l'API Crossref dans le format attendu pour notre formulaire.
     */
    private function normalizeMetadata(array $message, string $doi): array
    {
        $title = $message['title'][0] ?? null;

        $authors = null;
        if (!empty($message['author']) && is_array($message['author'])) {
            $authorList = [];
            foreach ($message['author'] as $author) {
                $family = $author['family'] ?? '';
                $given = $author['given'] ?? '';
                if ($family || $given) {
                    $authorList[] = trim($family . ($given ? ', ' . $given : ''));
                }
            }
            $authors = implode(' and ', $authorList);
        }

        $year = null;
        $dateSources = ['published-print', 'published-online', 'created'];
        foreach ($dateSources as $source) {
            if (!empty($message[$source]['date-parts'][0][0])) {
                $year = (string) $message[$source]['date-parts'][0][0];
                break;
            }
        }

        $journal = $message['container-title'][0] ?? $message['publisher'] ?? null;
        $volume = $message['volume'] ?? null;
        $pages = $message['page'] ?? null;
        $publisher = $message['publisher'] ?? null;

        return [
            'title' => $title,
            'authors' => $authors,
            'year' => $year,
            'journal' => $journal,
            'volume' => $volume,
            'pages' => $pages,
            'publisher' => $publisher,
            'doi' => $doi,
        ];
    }
}
