<?php

namespace App\Entity;

use App\Repository\BibliographyEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BibliographyEntryRepository::class)]
#[ORM\Table(name: 'bibliography_entry')]
#[ORM\Index(columns: ['sub_project_id'])]
#[ORM\Index(columns: ['cite_key'])]
class BibliographyEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SubProject::class, inversedBy: 'bibliographyEntries')]
    #[ORM\JoinColumn(name: 'sub_project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?SubProject $subProject = null;

    /**
     * Clé BibTeX unique dans le projet (ex: smith2023, doe_2019_title)
     */
    #[ORM\Column(length: 100)]
    private ?string $citeKey = null;

    /**
     * Type BibTeX : article, book, inproceedings, misc, phdthesis, mastersthesis, techreport…
     */
    #[ORM\Column(length: 30, options: ['default' => 'misc'])]
    private ?string $entryType = 'misc';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $title = null;

    /**
     * Auteurs au format BibTeX brut (ex: "Smith, John and Doe, Jane")
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $authors = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $year = null;

    /**
     * Revue, conférence ou éditeur
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $journal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $doi = null;

    /**
     * Ensemble brut des champs BibTeX sous forme de tableau JSON
     * ex: { "volume": "12", "pages": "1--10", "publisher": "Springer" }
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawData = null;

    /**
     * Source de l'entrée : 'bib_file' ou 'zotero'
     */
    #[ORM\Column(length: 20, options: ['default' => 'bib_file'])]
    private ?string $source = 'bib_file';

    /**
     * Identifiant externe Zotero (optionnel, pour la synchronisation)
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $zoteroKey = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubProject(): ?SubProject
    {
        return $this->subProject;
    }

    public function setSubProject(?SubProject $subProject): static
    {
        $this->subProject = $subProject;
        return $this;
    }

    public function getCiteKey(): ?string
    {
        return $this->citeKey;
    }

    public function setCiteKey(string $citeKey): static
    {
        $this->citeKey = $citeKey;
        return $this;
    }

    public function getEntryType(): ?string
    {
        return $this->entryType;
    }

    public function setEntryType(string $entryType): static
    {
        $this->entryType = $entryType;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getAuthors(): ?string
    {
        return $this->authors;
    }

    public function setAuthors(?string $authors): static
    {
        $this->authors = $authors;
        return $this;
    }

    public function getYear(): ?string
    {
        return $this->year;
    }

    public function setYear(?string $year): static
    {
        $this->year = $year;
        return $this;
    }

    public function getJournal(): ?string
    {
        return $this->journal;
    }

    public function setJournal(?string $journal): static
    {
        $this->journal = $journal;
        return $this;
    }

    public function getDoi(): ?string
    {
        return $this->doi;
    }

    public function setDoi(?string $doi): static
    {
        $this->doi = $doi;
        return $this;
    }

    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    public function setRawData(?array $rawData): static
    {
        $this->rawData = $rawData;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getZoteroKey(): ?string
    {
        return $this->zoteroKey;
    }

    public function setZoteroKey(?string $zoteroKey): static
    {
        $this->zoteroKey = $zoteroKey;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Retourne les auteurs formatés pour l'affichage (prénom Nom, …)
     * Ex: "Smith, John and Doe, Jane" → "Smith J., Doe J."
     */
    public function getAuthorsFormatted(): string
    {
        if (empty($this->authors)) {
            return '';
        }

        $authors = preg_split('/\s+and\s+/i', trim($this->authors));
        $formatted = [];

        foreach ($authors as $author) {
            $parts = explode(',', $author, 2);
            if (count($parts) === 2) {
                $lastName = trim($parts[0]);
                $firstName = trim($parts[1]);
                $initials = preg_replace('/([A-ZÀ-Ü])[a-zà-ü]+\.?\s*/u', '$1.', $firstName);
                $formatted[] = $lastName . ' ' . trim($initials);
            } else {
                $formatted[] = trim($author);
            }
        }

        return implode(', ', $formatted);
    }

    /**
     * Sérialise l'entrée en tableau pour l'API JSON
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'citeKey'     => $this->citeKey,
            'entryType'   => $this->entryType,
            'title'       => $this->title,
            'authors'     => $this->authors,
            'authorsFormatted' => $this->getAuthorsFormatted(),
            'year'        => $this->year,
            'journal'     => $this->journal,
            'doi'         => $this->doi,
            'source'      => $this->source,
            'zoteroKey'   => $this->zoteroKey,
            'rawData'     => $this->rawData ?? [],
            'createdAt'   => $this->createdAt?->format('Y-m-d H:i:s'),
        ];
    }
}
