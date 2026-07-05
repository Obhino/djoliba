<?php

namespace App\Service\Bibliography;

use App\Entity\BibliographicReference;
use App\Entity\User;
use App\Repository\BibliographicReferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use RenanBr\BibTexParser\Listener;
use RenanBr\BibTexParser\Parser;
use RenanBr\BibTexParser\Processor;

class BibtexImporter
{
    public function __construct(
        private BibliographicReferenceRepository $referenceRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Importe des références à partir d'une chaîne de caractères contenant du BibTeX.
     *
     * @return array{imported: int, total: int}
     */
    public function import(User $user, string $bibContent): array
    {
        // Initialiser le listener avec des processeurs standards (sans LatexToUnicodeProcessor)
        $listener = new Listener();
        $listener->addProcessor(new Processor\TagNameCaseProcessor(CASE_LOWER));
        $listener->addProcessor(new Processor\TrimProcessor());

        $parser = new Parser();
        $parser->addListener($listener);

        try {
            $parser->parseString($bibContent);
        } catch (\Exception $e) {
            throw new \RuntimeException("Erreur lors du parsing du fichier BibTeX : " . $e->getMessage(), 0, $e);
        }

        $entries = $listener->export();
        $importedCount = 0;
        $assignedKeys = [];

        foreach ($entries as $entry) {
            $reference = new BibliographicReference();
            $reference->setUser($user);

            // 1. Déterminer le type d'entrée (type BibTeX : article, book, inproceedings, etc.)
            $entryType = $entry['_type'] ?? 'misc';
            $reference->setEntryType(strtolower($entryType));

            // 2. Extraire les auteurs (champ 'author' ou 'editor')
            $authors = $entry['author'] ?? $entry['editor'] ?? null;
            $reference->setAuthors($this->cleanValue($authors));

            // 3. Extraire l'année (4 chiffres)
            $year = $entry['year'] ?? $entry['date'] ?? null;
            if ($year) {
                preg_match('/(\d{4})/', $year, $matches);
                $year = $matches[1] ?? $year;
            }
            $reference->setYear($this->cleanValue($year));

            // 4. Clé de citation (générée automatiquement si absente ou vide)
            $citeKey = $entry['citation-key'] ?? null;
            if (empty(trim((string)$citeKey))) {
                $citeKey = $this->generateAutomaticKey($authors, $year);
            }

            // Assurer l'unicité de la clé pour cet utilisateur
            $uniqueCiteKey = $this->makeKeyUnique($user, $citeKey, $assignedKeys);
            $reference->setCiteKey($uniqueCiteKey);

            // 5. Extraire les autres champs
            $reference->setTitle($this->cleanValue($entry['title'] ?? null));
            
            // Journal / Livre / Éditeur parent
            $journal = $entry['journal'] ?? $entry['journaltitle'] ?? $entry['booktitle'] ?? $entry['publisher'] ?? $entry['school'] ?? $entry['institution'] ?? null;
            $reference->setJournal($this->cleanValue($journal));

            $reference->setDoi($this->cleanValue($entry['doi'] ?? null));
            
            // source
            $reference->setSource('bib_file');

            // Données brutes complémentaires (on nettoie les métadonnées internes du parser)
            $rawData = $entry;
            unset($rawData['_type'], $rawData['citation-key']);
            
            // Nettoyer les valeurs de rawData
            foreach ($rawData as $k => $v) {
                if (is_string($v)) {
                    $rawData[$k] = $this->cleanValue($v);
                }
            }
            $reference->setRawData($rawData);

            $this->entityManager->persist($reference);
            $importedCount++;
        }

        $this->entityManager->flush();

        return [
            'imported' => $importedCount,
            'total' => count($entries)
        ];
    }

    /**
     * Génère une clé automatique de type "Smith2023" à partir des auteurs et de l'année.
     */
    private function generateAutomaticKey(?string $authors, ?string $year): string
    {
        $lastName = 'Unknown';

        if (!empty($authors)) {
            // Nettoyer et séparer par "and" pour obtenir le premier auteur
            $firstAuthor = preg_split('/\s+and\s+/i', trim($authors))[0];
            
            if (str_contains($firstAuthor, ',')) {
                // Format "LastName, FirstName"
                $parts = explode(',', $firstAuthor);
                $lastName = trim($parts[0]);
            } else {
                // Format "FirstName LastName"
                $parts = explode(' ', $firstAuthor);
                $lastName = end($parts);
            }

            // Ne garder que les caractères alphanumériques
            $lastName = preg_replace('/[^a-zA-Z0-9]/', '', $lastName);
            
            if (empty($lastName)) {
                $lastName = 'Author';
            }
        }

        $cleanYear = $year ?: date('Y');
        return ucfirst(strtolower($lastName)) . $cleanYear;
    }

    /**
     * Rend la clé de citation unique pour la bibliothèque de l'utilisateur.
     * En cas de collision, ajoute un suffixe alphabétique (a, b, c, ...).
     */
    private function makeKeyUnique(User $user, string $citeKey, array &$assignedKeys): string
    {
        $baseKey = $citeKey;
        $suffix = 'a';
        $attempts = 0;

        while (true) {
            // Vérifier d'abord dans les clés déjà affectées lors de cet import
            if (!in_array($citeKey, $assignedKeys)) {
                // Si pas en mémoire, vérifier en base de données
                $existing = $this->referenceRepository->findOneBy([
                    'user' => $user,
                    'citeKey' => $citeKey
                ]);

                if (!$existing) {
                    $assignedKeys[] = $citeKey;
                    return $citeKey;
                }
            }

            $citeKey = $baseKey . $suffix;
            $suffix = $this->incrementSuffix($suffix);
            
            $attempts++;
            if ($attempts > 200) {
                $uniqueKey = $baseKey . '_' . uniqid();
                $assignedKeys[] = $uniqueKey;
                return $uniqueKey;
            }
        }
    }

    /**
     * Incrémente une chaîne alphabétique (a -> b, z -> aa, etc.)
     */
    private function incrementSuffix(string $suffix): string
    {
        return ++$suffix;
    }

    /**
     * Nettoie et décode les accents/accolades LaTeX.
     */
    private function cleanValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $latexAccents = [
            "\\'a" => 'á', "\\'e" => 'é', "\\'i" => 'í', "\\'o" => 'ó', "\\'u" => 'ú',
            "\\'A" => 'Á', "\\'E" => 'É', "\\'I" => 'Í', "\\'O" => 'Ó', "\\'U" => 'Ú',
            "\\`a" => 'à', "\\`e" => 'è', "\\`i" => 'ì', "\\`o" => 'ù', "\\`u" => 'ù',
            "\\`A" => 'À', "\\`E" => 'È', "\\`I" => 'Ì', "\\`O" => 'Ò', "\\`U" => 'Ù',
            "\\^a" => 'â', "\\^e" => 'ê', "\\^i" => 'î', "\\^o" => 'ô', "\\^u" => 'û',
            "\\^A" => 'Â', "\\^E" => 'Ê', "\\^I" => 'Î', "\\^O" => 'Ô', "\\^U" => 'Û',
            '\\"a' => 'ä', '\\"e' => 'ë', '\\"i' => 'ï', '\\"o' => 'ö', '\\"u' => 'ü',
            '\\"A' => 'Ä', '\\"E' => 'Ë', '\\"I' => 'Ï', '\\"O' => 'Ö', '\\"U' => 'Ü',
            "\\~n" => 'ñ', "\\~N" => 'Ñ', "\\~a" => 'ã', "\\~o" => 'õ',
            "\\c{c}" => 'ç', "\\c{C}" => 'Ç',
            "\\ss" => 'ß',
            "\\ae" => 'æ', "\\AE" => 'Æ',
            "\\oe" => 'œ', "\\OE" => 'Œ',
            "\\aa" => 'å', "\\AA" => 'Å',
            "--" => '–', "---" => '—',
        ];

        $value = strtr($value, $latexAccents);
        $value = preg_replace('/\{([^{}]*)\}/', '$1', $value);
        $value = str_replace(['{', '}'], '', $value);
        $value = strtr($value, $latexAccents);
        $value = preg_replace('/\\\\([áéíóúÁÉÍÓÚàèìòùÀÈÌÒÙâêîôûÂÊÎÔÛäëïöüÄËÏÖÜñÑãõçÇßæÆœŒåÅ])/', '$1', $value);
        $value = str_replace('\\', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }
}
