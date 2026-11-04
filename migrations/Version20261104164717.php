<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104164717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Écran appairé d\'un dispositif : haché du secret de son cookie.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device ADD screen_secret_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_screen_secret_hash ON device (screen_secret_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_device_screen_secret_hash');
        $this->addSql('ALTER TABLE device DROP screen_secret_hash');
    }
}
