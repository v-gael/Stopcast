<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929175027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Donne un propriétaire obligatoire à chaque dispositif ; supprimer un compte ou un dispositif emporte ce qui en dépend. Échoue si des dispositifs existent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device ADD owner_id INT NOT NULL');
        $this->addSql('ALTER TABLE device ADD CONSTRAINT FK_92FB68E7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_92FB68E7E3C61F9 ON device (owner_id)');
        $this->addSql('ALTER TABLE display_configuration DROP CONSTRAINT fk_2f6f759394a4c7d4');
        $this->addSql('ALTER TABLE display_configuration ADD CONSTRAINT FK_2F6F759394A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device DROP CONSTRAINT FK_92FB68E7E3C61F9');
        $this->addSql('DROP INDEX IDX_92FB68E7E3C61F9');
        $this->addSql('ALTER TABLE device DROP owner_id');
        $this->addSql('ALTER TABLE display_configuration DROP CONSTRAINT FK_2F6F759394A4C7D4');
        $this->addSql('ALTER TABLE display_configuration ADD CONSTRAINT fk_2f6f759394a4c7d4 FOREIGN KEY (device_id) REFERENCES device (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
