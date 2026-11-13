<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113123914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne trip.terminus_stop_sequence : stop_sequence du dernier arrêt de chaque trajet GTFS.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip ADD terminus_stop_sequence INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip DROP terminus_stop_sequence');
    }
}
