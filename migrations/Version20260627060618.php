<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout d'index de performance sur les colonnes les plus requêtées.
 *
 * Utilise CREATE INDEX CONCURRENTLY (PostgreSQL) pour éviter le verrouillage
 * des tables en écriture pendant la création des index.
 *
 * Index ajoutés :
 *  - interaction.created_at   → tri chronologique des interactions
 *  - project.status           → filtrage par statut (active, archived, deleted)
 *  - project_activity.created_at → tri chronologique du journal d'activité
 *  - sub_project.status       → filtrage par statut des sous-projets
 *
 * Les index suivants existaient déjà (créés avec les clés étrangères) :
 *  - sub_project.research_project_id, sub_project.user_id, sub_project.type
 *  - project.user_id, project.type
 *  - interaction.project_id, interaction.sub_project_id
 *  - document.project_id, document.sub_project_id, document.user_id
 *  - project_activity.research_project_id, project_activity.user_id
 */
final class Version20260627060618 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout d\'index de performance (CONCURRENTLY) sur interaction.created_at, project.status, project_activity.created_at, sub_project.status';
    }

    /**
     * Désactive le wrapping transactionnel car CREATE INDEX CONCURRENTLY
     * ne peut pas s'exécuter à l'intérieur d'un bloc BEGIN/COMMIT.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS IDX_378DFDA78B8E8428 ON interaction (created_at)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS IDX_2FB3D0EE7B00651C ON project (status)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS IDX_913A82818B8E8428 ON project_activity (created_at)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS IDX_506B8C1A7B00651C ON sub_project (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS IDX_378DFDA78B8E8428');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS IDX_2FB3D0EE7B00651C');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS IDX_913A82818B8E8428');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS IDX_506B8C1A7B00651C');
    }
}
