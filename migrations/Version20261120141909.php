<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120141909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Colonne device.theme : couleurs de l'écran, sombre pour les dispositifs existants.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE device ADD theme VARCHAR(16) DEFAULT 'dark' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device DROP theme');
    }
}
