<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103234621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cadences de la page d\'affichage par dispositif : durée d\'un écran et rafraîchissement des données.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device ADD screen_duration SMALLINT DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE device ADD refresh_interval SMALLINT DEFAULT 60 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device DROP screen_duration');
        $this->addSql('ALTER TABLE device DROP refresh_interval');
    }
}
