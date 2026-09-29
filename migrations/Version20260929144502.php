<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929144502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retire quay.active et route.active : deprecated_at suffit à savoir si un quai ou une ligne est actif.';
    }

    public function up(Schema $schema): void
    {
        // Aucune dépréciation ne doit se perdre avec la colonne active.
        $this->addSql('UPDATE quay SET deprecated_at = NOW() WHERE active = false AND deprecated_at IS NULL');
        $this->addSql('UPDATE route SET deprecated_at = NOW() WHERE active = false AND deprecated_at IS NULL');

        $this->addSql('DROP INDEX idx_quay_active');
        $this->addSql('ALTER TABLE quay DROP active');
        $this->addSql('ALTER TABLE route DROP active');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quay ADD active BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('CREATE INDEX idx_quay_active ON quay (active)');
        $this->addSql('ALTER TABLE route ADD active BOOLEAN DEFAULT true NOT NULL');

        $this->addSql('UPDATE quay SET active = false WHERE deprecated_at IS NOT NULL');
        $this->addSql('UPDATE route SET active = false WHERE deprecated_at IS NOT NULL');
    }
}
