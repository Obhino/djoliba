<?php

namespace App\Service\Bibliography;

use App\Entity\BibliographicReference;
use App\Entity\ProjectBibliography;
use App\Entity\ResearchProject;
use App\Entity\User;
use App\Repository\BibliographicReferenceRepository;
use App\Repository\ProjectBibliographyRepository;
use Doctrine\ORM\EntityManagerInterface;

class BibliographicReferenceManager
{
    public function __construct(
        private BibliographicReferenceRepository $referenceRepository,
        private ProjectBibliographyRepository $projectBibRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Crée et persiste une nouvelle référence bibliographique globale pour un utilisateur.
     */
    public function createReference(User $user, array $data): BibliographicReference
    {
        $reference = new BibliographicReference();
        $reference->setUser($user);
        
        $this->hydrateReference($reference, $data);
        
        $this->entityManager->persist($reference);
        $this->entityManager->flush();

        return $reference;
    }

    /**
     * Récupère toutes les références d'un utilisateur.
     *
     * @return BibliographicReference[]
     */
    public function getReferencesForUser(User $user): array
    {
        return $this->referenceRepository->findBy(
            ['user' => $user],
            ['authors' => 'ASC', 'year' => 'DESC']
        );
    }

    /**
     * Récupère les références associées à la bibliographie d'un projet de recherche.
     *
     * @return BibliographicReference[]
     */
    public function getReferencesForProject(ResearchProject $project): array
    {
        $projectBibliography = $project->getProjectBibliography();
        if (!$projectBibliography) {
            return [];
        }

        return $projectBibliography->getReferences()->toArray();
    }

    /**
     * Met à jour les champs d'une référence bibliographique existante.
     */
    public function updateReference(BibliographicReference $reference, array $data): BibliographicReference
    {
        $this->hydrateReference($reference, $data);
        $this->entityManager->flush();

        return $reference;
    }

    /**
     * Supprime une référence bibliographique globale.
     */
    public function deleteReference(BibliographicReference $reference): void
    {
        $this->entityManager->remove($reference);
        $this->entityManager->flush();
    }

    /**
     * Recherche dans les références de l'utilisateur avec filtres.
     *
     * @return BibliographicReference[]
     */
    public function searchReferences(User $user, string $query, array $filters = []): array
    {
        if (empty(trim($query))) {
            $references = $this->getReferencesForUser($user);
        } else {
            $references = $this->referenceRepository->searchByUser($user, $query);
        }

        // Appliquer des filtres optionnels en mémoire si spécifié
        if (!empty($filters)) {
            $references = array_filter($references, function (BibliographicReference $ref) use ($filters) {
                if (isset($filters['entryType']) && $ref->getEntryType() !== $filters['entryType']) {
                    return false;
                }
                if (isset($filters['source']) && $ref->getSource() !== $filters['source']) {
                    return false;
                }
                return true;
            });
            // Réindexer le tableau après filtrage
            $references = array_values($references);
        }

        return $references;
    }

    /**
     * Associe une référence à la bibliographie d'un projet de recherche.
     */
    public function addToProject(BibliographicReference $reference, ResearchProject $project): void
    {
        $projectBibliography = $project->getProjectBibliography();
        if (!$projectBibliography) {
            $projectBibliography = new ProjectBibliography();
            $projectBibliography->setResearchProject($project);
            $this->entityManager->persist($projectBibliography);
            
            // Lier également le côté inversé
            $project->setProjectBibliography($projectBibliography);
        }

        $projectBibliography->addReference($reference);
        $this->entityManager->flush();
    }

    /**
     * Retire une référence de la bibliographie d'un projet de recherche.
     */
    public function removeFromProject(BibliographicReference $reference, ResearchProject $project): void
    {
        $projectBibliography = $project->getProjectBibliography();
        if ($projectBibliography) {
            $projectBibliography->removeReference($reference);
            $this->entityManager->flush();
        }
    }

    /**
     * Hydrate les propriétés de l'entité BibliographicReference avec les données fournies.
     */
    private function hydrateReference(BibliographicReference $reference, array $data): void
    {
        if (isset($data['citeKey'])) {
            $reference->setCiteKey((string) $data['citeKey']);
        }
        if (isset($data['entryType'])) {
            $reference->setEntryType((string) $data['entryType']);
        }
        if (array_key_exists('title', $data)) {
            $reference->setTitle($data['title'] !== null ? (string) $data['title'] : null);
        }
        if (array_key_exists('authors', $data)) {
            $reference->setAuthors($data['authors'] !== null ? (string) $data['authors'] : null);
        }
        if (array_key_exists('year', $data)) {
            $reference->setYear($data['year'] !== null ? (string) $data['year'] : null);
        }
        if (array_key_exists('journal', $data)) {
            $reference->setJournal($data['journal'] !== null ? (string) $data['journal'] : null);
        }
        if (array_key_exists('doi', $data)) {
            $reference->setDoi($data['doi'] !== null ? (string) $data['doi'] : null);
        }
        if (array_key_exists('rawData', $data)) {
            $reference->setRawData($data['rawData']);
        }
        if (isset($data['source'])) {
            $reference->setSource((string) $data['source']);
        }
        if (array_key_exists('zoteroKey', $data)) {
            $reference->setZoteroKey($data['zoteroKey'] !== null ? (string) $data['zoteroKey'] : null);
        }
    }
}
