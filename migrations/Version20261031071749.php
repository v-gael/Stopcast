<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031071749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dernière connexion et avertissement d\'inactivité des comptes ; les comptes activés partent de la date de la migration.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD inactivity_warned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Heure de PHP, comme les dates écrites par l'application.
        $this->addSql('UPDATE app_user SET last_login_at = :now WHERE password IS NOT NULL', [
            'now' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP last_login_at');
        $this->addSql('ALTER TABLE app_user DROP inactivity_warned_at');
    }
}
