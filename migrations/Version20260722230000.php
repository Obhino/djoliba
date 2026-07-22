<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour ajouter category et relative_path à la table document
 */
final class Version20260722230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des colonnes category et relative_path à la table document pour la gestion structurée des fichiers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD category VARCHAR(50) DEFAULT \'documents\' NOT NULL');
        $this->addSql('ALTER TABLE document ADD relative_path VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP category');
        $this->addSql('ALTER TABLE document DROP relative_path');
    }
}
