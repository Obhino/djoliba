<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Add academic_status, biography, and google_scholar to user table
 */
final class Version20260531212200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add academic_status, biography, and google_scholar columns to user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD academic_status VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD biography TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD google_scholar VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP academic_status');
        $this->addSql('ALTER TABLE "user" DROP biography');
        $this->addSql('ALTER TABLE "user" DROP google_scholar');
    }
}
