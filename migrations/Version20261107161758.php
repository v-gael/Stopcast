<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107161758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Dernier contact de l'écran de chaque dispositif, écrit au plus toutes les 5 minutes.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device ADD last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device DROP last_seen_at');
    }
}
