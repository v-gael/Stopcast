<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026160049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mot de passe facultatif : un compte invité n\'en a pas tant qu\'il est en attente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ALTER password DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ALTER password SET NOT NULL');
    }
}
