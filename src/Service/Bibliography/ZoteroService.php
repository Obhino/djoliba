<?php

namespace App\Service\Bibliography;

use App\Entity\BibliographyEntry;
use App\Entity\SubProject;
use App\Repository\BibliographyEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ZoteroService — Service d'intégration avec l'API Web de Zotero.
 *
 * Responsabilités :
 * - Valider les identifiants de l'API Zotero d'un utilisateur.
 * - Récupérer les collections pour faciliter la navigation.
 * - Récupérer et chercher des références Zotero.
 * - Synchroniser (importer/mettre à jour) les références sélectionnées dans la BDD locale.
 */
class ZoteroService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private BibliographyEntryRepository $repository,
    ) {
    }

    /**
     * Valide les identifiants de connexion Zotero en effectuant une requête légère.
     */
    public function validateCredentials(string $userId, string $apiKey): bool
    {
        if (empty($userId) || empty($apiKey)) {
            return false;
        }

        try {
            $response = $this->httpClient->request('GET', sprintf('https://api.zotero.org/users/%s/collections', $userId), [
                'headers' => [
                    'Zotero-API-Key'     => $apiKey,
                    'Zotero-API-Version' => '3',
                ],
                'query' => [
                    'limit' => 1
                ]
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Récupère la liste des collections Zotero de l'utilisateur.
     *
     * @return array<array{key: string, name: string, parentCollection: string|null}>
     */
    public function fetchCollections(string $userId, string $apiKey): array
    {
        try {
            $response = $this->httpClient->request('GET', sprintf('https://api.zotero.org/users/%s/collections', $userId), [
                'headers' => [
                    'Zotero-API-Key'     => $apiKey,
                    'Zotero-API-Version' => '3',
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $collections = [];
            foreach ($response->toArray() as $col) {
                $collections[] = [
                    'key'              => $col['key'],
                    'name'             => $col['data']['name'] ?? '',
                    'parentCollection' => $col['data']['parentCollection'] ?? null,
                ];
            }

            return $collections;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Récupère les références depuis Zotero selon une collection et/ou une recherche.
     *
     * @return array Normalized array of Zotero entries.
     */
    public function fetchItems(string $userId, string $apiKey, ?string $collectionKey = null, ?string $search = null): array
    {
        try {
            $url = sprintf('https://api.zotero.org/users/%s/items', $userId);
            if ($collectionKey) {
                $url = sprintf('https://api.zotero.org/users/%s/collections/%s/items', $userId, $collectionKey);
            }

            $query = [
                'limit'    => 100,
                'itemType' => '-attachment || -note', // Exclure pièces jointes et notes
            ];

            if (!empty($search)) {
                $query['q'] = $search;
            }

            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Zotero-API-Key'     => $apiKey,
                    'Zotero-API-Version' => '3',
                ],
                'query' => $query
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $items = [];
            foreach ($response->toArray() as $item) {
                $data = $item['data'] ?? [];
                if (empty($data['key']) || in_array($data['itemType'] ?? '', ['attachment', 'note'], true)) {
                    continue;
                }

                $items[] = $this->normalizeZoteroItem($item);
            }

            return $items;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Importe des références sélectionnées de Zotero dans un sous-projet local.
     *
     * @param string[] $zoteroKeys Liste de clés Zotero à importer
     * @return array{imported: int, updated: int}
     */
    public function importSelectedItems(SubProject $subProject, array $zoteroKeys, string $userId, string $apiKey): array
    {
        if (empty($zoteroKeys)) {
            return ['imported' => 0, 'updated' => 0];
        }

        try {
            // Zotero permet de charger plusieurs éléments par clés séparées par des virgules
            $response = $this->httpClient->request('GET', sprintf('https://api.zotero.org/users/%s/items', $userId), [
                'headers' => [
                    'Zotero-API-Key'     => $apiKey,
                    'Zotero-API-Version' => '3',
                ],
                'query' => [
                    'itemKey' => implode(',', $zoteroKeys)
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Erreur API Zotero : Code HTTP ' . $response->getStatusCode());
            }

            $zoteroItems = $response->toArray();
            $imported = 0;
            $updated  = 0;

            foreach ($zoteroItems as $itemData) {
                $normalized = $this->normalizeZoteroItem($itemData);
                $zoteroKey  = $normalized['zoteroKey'];
                $citeKey    = $normalized['citeKey'];

                // 1. Chercher si existant par zoteroKey
                $entry = $this->repository->findOneBy([
                    'subProject' => $subProject,
                    'zoteroKey'  => $zoteroKey
                ]);

                // 2. Chercher sinon par citeKey
                if (!$entry) {
                    $entry = $this->repository->findOneBy([
                        'subProject' => $subProject,
                        'citeKey'    => $citeKey
                    ]);
                }

                $isNew = false;
                if (!$entry) {
                    $entry = new BibliographyEntry();
                    $entry->setSubProject($subProject);
                    $entry->setCiteKey($citeKey);
                    $entry->setZoteroKey($zoteroKey);
                    $entry->setSource('zotero');
                    $isNew = true;
                }

                // Remplir / mettre à jour
                $entry->setEntryType($normalized['entryType']);
                $entry->setTitle($normalized['title']);
                $entry->setAuthors($normalized['authors']);
                $entry->setYear($normalized['year']);
                $entry->setJournal($normalized['journal']);
                $entry->setDoi($normalized['doi']);
                $entry->setRawData($normalized['rawData']);

                if ($isNew) {
                    $this->entityManager->persist($entry);
                    $imported++;
                } else {
                    $updated++;
                }
            }

            $this->entityManager->flush();

            return [
                'imported' => $imported,
                'updated'  => $updated
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Échec de l\'importation Zotero : ' . $e->getMessage());
        }
    }

    /**
     * Normalise un élément brut Zotero au format attendu par la BDD Djoliba.
     */
    private function normalizeZoteroItem(array $item): array
    {
        $data       = $item['data'] ?? [];
        $key        = $data['key'] ?? '';
        $zoteroType = $data['itemType'] ?? 'document';

        // Mappage Zotero -> BibTeX
        $entryType = match ($zoteroType) {
            'journalArticle'  => 'article',
            'book'            => 'book',
            'bookSection'     => 'incollection',
            'conferencePaper' => 'inproceedings',
            'thesis'          => 'phdthesis',
            'report'          => 'techreport',
            default           => 'misc',
        };

        // Extraction et formatage des auteurs
        $creatorsList = $data['creators'] ?? [];
        $authorsArray = [];
        $firstAuthorLastName = 'anon';

        foreach ($creatorsList as $index => $creator) {
            $lastName  = $creator['lastName'] ?? $creator['name'] ?? '';
            $firstName = $creator['firstName'] ?? '';

            if ($lastName) {
                if ($index === 0) {
                    $firstAuthorLastName = $lastName;
                }
                $authorsArray[] = $firstName ? sprintf('%s, %s', $lastName, $firstName) : $lastName;
            }
        }
        $authors = implode(' and ', $authorsArray);

        // Extraction de l'année
        $dateStr = $data['date'] ?? '';
        $year    = null;
        if ($dateStr && preg_match('/(\d{4})/', $dateStr, $matches)) {
            $year = $matches[1];
        }

        // Génération de clé de citation BibTeX robuste (smith2023)
        $cleanLastName = preg_replace('/[^a-zA-Z0-9]/', '', iconv('utf-8', 'us-ascii//TRANSLIT', $firstAuthorLastName));
        $citeKey = strtolower($cleanLastName) . ($year ?? '');
        if (empty($citeKey)) {
            $citeKey = 'zotero_' . strtolower($key);
        }

        // Revue/journal/éditeur
        $journal = $data['publicationTitle'] ?? $data['publisher'] ?? $data['university'] ?? $data['institution'] ?? null;

        // DOI
        $doi = $data['DOI'] ?? $data['doi'] ?? null;

        // rawData (autres champs pertinents)
        $rawData = [];
        $fieldsToCopy = ['volume', 'issue', 'pages', 'publisher', 'place', 'url', 'abstractNote', 'ISBN', 'ISSN'];
        foreach ($fieldsToCopy as $field) {
            if (!empty($data[$field])) {
                $rawData[$field] = $data[$field];
            }
        }

        return [
            'zoteroKey' => $key,
            'citeKey'   => $citeKey,
            'entryType' => $entryType,
            'title'     => $data['title'] ?? '(sans titre)',
            'authors'   => $authors ?: null,
            'year'      => $year,
            'journal'   => $journal,
            'doi'       => $doi,
            'rawData'   => $rawData,
        ];
    }
}
