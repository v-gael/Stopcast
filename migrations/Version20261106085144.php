<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106085144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jeton des dispositifs supprimé avec l\'API JSON : l\'écran s\'authentifie par son cookie.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_device_token');
        $this->addSql('ALTER TABLE device DROP token');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device ADD token VARCHAR(64) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_token ON device (token)');
    }
}
