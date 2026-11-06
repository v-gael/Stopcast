<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106221312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Emails des comptes normalisés (sans espaces autour, en minuscules) ; échoue si deux comptes ne diffèrent que par la casse.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE app_user SET email = LOWER(TRIM(email)) WHERE email <> LOWER(TRIM(email))');
    }

    public function down(Schema $schema): void
    {
        // Rien à défaire : la casse d'origine est perdue, et une adresse en
        // minuscules reste valable sans la normalisation.
    }
}
