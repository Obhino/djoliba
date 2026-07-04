<?php

namespace App\Service\Bibliography;

use App\Entity\BibliographyEntry;
use App\Entity\SubProject;
use App\Repository\BibliographyEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * BibliographyManager — Orchestre la gestion des références bibliographiques.
 *
 * Responsabilités :
 * - Import et persistence des entrées depuis un fichier .bib
 * - Récupération et recherche des références d'un projet
 * - Formatage des citations en plusieurs styles (APA, numérique, Chicago)
 * - Génération du bloc bibliographique LaTeX
 */
class BibliographyManager
{
    public function __construct(
        private BibParser $bibParser,
        private BibliographyEntryRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Importe les références depuis un contenu BibTeX dans un SubProject.
     *
     * Les doublons (même citeKey) sont mis à jour plutôt que recréés.
     *
     * @return array{imported: int, updated: int, skipped: int, total: int}
     */
    public function importFromBib(SubProject $subProject, string $bibContent): array
    {
        $entries = $this->bibParser->parse($bibContent);

        $imported = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($entries as $citeKey => $data) {
            $fields = $data['fields'];

            // Chercher un doublon existant
            $existing = $this->repository->findByCiteKey($subProject, $citeKey);

            if ($existing) {
                // Mise à jour de l'entrée existante
                $this->fillEntry($existing, $data, $fields);
                $updated++;
            } else {
                $entry = new BibliographyEntry();
                $entry->setSubProject($subProject);
                $entry->setCiteKey($citeKey);
                $entry->setSource('bib_file');
                $this->fillEntry($entry, $data, $fields);
                $this->entityManager->persist($entry);
                $imported++;
            }
        }

        $this->entityManager->flush();

        return [
            'imported' => $imported,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'total'    => count($entries),
        ];
    }

    /**
     * Remplit les champs d'une entité BibliographyEntry depuis les données parsées.
     */
    private function fillEntry(BibliographyEntry $entry, array $data, array $fields): void
    {
        $entry->setEntryType($data['type']);

        // Titre : champ 'title'
        $title = $this->bibParser->extractField($fields, ['title']);
        $entry->setTitle($title ?: null);

        // Auteurs : 'author' ou 'editor'
        $authors = $this->bibParser->extractField($fields, ['author', 'editor']);
        $entry->setAuthors($authors ?: null);

        // Année : 'year' ou 'date' (extrait les 4 premiers chiffres)
        $year = $this->bibParser->extractField($fields, ['year', 'date']);
        if ($year) {
            preg_match('/(\d{4})/', $year, $m);
            $year = $m[1] ?? $year;
        }
        $entry->setYear($year ?: null);

        // Revue / conférence / éditeur selon le type
        $journal = $this->bibParser->extractField($fields, [
            'journal', 'journaltitle', 'booktitle', 'publisher', 'school', 'institution',
        ]);
        $entry->setJournal($journal ?: null);

        // DOI
        $doi = $this->bibParser->extractField($fields, ['doi']);
        $entry->setDoi($doi ?: null);

        // Données brutes complètes (pour l'export LaTeX fidèle)
        $entry->setRawData($fields);
    }

    /**
     * Retourne toutes les références d'un SubProject.
     *
     * @return BibliographyEntry[]
     */
    public function getEntries(SubProject $subProject): array
    {
        return $this->repository->findBySubProject($subProject);
    }

    /**
     * Recherche des références par mot-clé dans un SubProject.
     *
     * @return BibliographyEntry[]
     */
    public function searchEntries(SubProject $subProject, string $query): array
    {
        if (empty(trim($query))) {
            return $this->getEntries($subProject);
        }

        return $this->repository->searchBySubProject($subProject, $query);
    }

    /**
     * Supprime une entrée bibliographique.
     */
    public function deleteEntry(BibliographyEntry $entry): void
    {
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }

    /**
     * Supprime toutes les références d'un SubProject.
     */
    public function deleteAll(SubProject $subProject): int
    {
        return $this->repository->deleteAllForSubProject($subProject);
    }

    /**
     * Formate une citation selon le style demandé.
     *
     * Styles supportés : 'apa', 'numeric', 'chicago'
     *
     * @param int $index Position dans la bibliographie (utilisé pour le style numérique)
     */
    public function formatCitation(BibliographyEntry $entry, string $style = 'apa', int $index = 1): string
    {
        return match ($style) {
            'numeric' => $this->formatNumeric($entry, $index),
            'chicago' => $this->formatChicago($entry),
            default   => $this->formatApa($entry),
        };
    }

    /**
     * Formate la citation inline (dans le texte) selon le style.
     */
    public function formatInlineCitation(BibliographyEntry $entry, string $style = 'apa', int $index = 1): string
    {
        return match ($style) {
            'numeric' => '[' . $index . ']',
            'chicago' => '(' . $this->getLastAuthorName($entry) . ', ' . ($entry->getYear() ?? 's.d.') . ')',
            default   => '(' . $this->getLastAuthorName($entry) . ', ' . ($entry->getYear() ?? 's.d.') . ')',
        };
    }

    // ─── Styles de formatage ─────────────────────────────────────────────────

    private function formatApa(BibliographyEntry $entry): string
    {
        $parts = [];

        // Auteurs
        $authors = $entry->getAuthorsFormatted();
        if ($authors) {
            $parts[] = $authors;
        }

        // Année
        $year = $entry->getYear();
        if ($year) {
            $parts[] = '(' . $year . ')';
        }

        // Titre
        $title = $entry->getTitle();
        if ($title) {
            $parts[] = '*' . $title . '*';
        }

        // Journal/éditeur
        $journal = $entry->getJournal();
        if ($journal) {
            $parts[] = '*' . $journal . '*';
        }

        // Numéro/volume depuis rawData
        $raw = $entry->getRawData() ?? [];
        if (!empty($raw['volume'])) {
            $vol = $raw['volume'];
            if (!empty($raw['number'])) {
                $vol .= '(' . $raw['number'] . ')';
            }
            $parts[] = $vol;
        }

        // Pages
        if (!empty($raw['pages'])) {
            $parts[] = str_replace('--', '–', $raw['pages']);
        }

        // DOI
        $doi = $entry->getDoi();
        if ($doi) {
            $parts[] = 'https://doi.org/' . ltrim($doi, 'https://doi.org/');
        }

        return implode('. ', $parts) . '.';
    }

    private function formatChicago(BibliographyEntry $entry): string
    {
        $parts = [];

        $authors = $entry->getAuthorsFormatted();
        if ($authors) {
            $parts[] = $authors;
        }

        $title = $entry->getTitle();
        if ($title) {
            $parts[] = '"' . $title . '"';
        }

        $journal = $entry->getJournal();
        if ($journal) {
            $parts[] = '*' . $journal . '*';
        }

        $raw = $entry->getRawData() ?? [];
        if (!empty($raw['volume'])) {
            $parts[] = $raw['volume'];
        }

        $year = $entry->getYear();
        if ($year) {
            $parts[] = '(' . $year . ')';
        }

        if (!empty($raw['pages'])) {
            $parts[] = str_replace('--', '–', $raw['pages']);
        }

        return implode(', ', $parts) . '.';
    }

    private function formatNumeric(BibliographyEntry $entry, int $index): string
    {
        $raw   = $entry->getRawData() ?? [];
        $parts = [];

        $authors = $entry->getAuthorsFormatted();
        if ($authors) {
            $parts[] = $authors;
        }

        $title = $entry->getTitle();
        if ($title) {
            $parts[] = '"' . $title . '"';
        }

        $journal = $entry->getJournal();
        if ($journal) {
            $parts[] = $journal;
        }

        $year = $entry->getYear();
        if ($year) {
            $parts[] = $year;
        }

        if (!empty($raw['pages'])) {
            $parts[] = 'p. ' . str_replace('--', '–', $raw['pages']);
        }

        $doi = $entry->getDoi();
        if ($doi) {
            $parts[] = 'doi: ' . $doi;
        }

        return '[' . $index . '] ' . implode(', ', $parts) . '.';
    }

    /**
     * Extrait le nom du premier auteur pour la citation inline.
     */
    private function getLastAuthorName(BibliographyEntry $entry): string
    {
        $authors = $entry->getAuthors();
        if (empty($authors)) {
            return 'Anon.';
        }

        // Prendre le premier auteur avant "and"
        $firstAuthor = explode(' and ', $authors, 2)[0];
        // Format "Nom, Prénom" → "Nom"
        $parts = explode(',', $firstAuthor, 2);

        return trim($parts[0]);
    }

    /**
     * Sérialise une liste d'entrées en tableau JSON pour l'API.
     *
     * @param BibliographyEntry[] $entries
     * @return array[]
     */
    public function toApiArray(array $entries): array
    {
        return array_values(array_map(
            fn (BibliographyEntry $e) => $e->toArray(),
            $entries
        ));
    }
}
