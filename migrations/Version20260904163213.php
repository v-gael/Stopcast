<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904163213 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE device (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(255) NOT NULL, token VARCHAR(64) NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_token ON device (token)');
        $this->addSql('CREATE TABLE display_configuration (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(255) DEFAULT NULL, position INTEGER DEFAULT 0 NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, device_id INTEGER NOT NULL, quay_id INTEGER NOT NULL, CONSTRAINT FK_2F6F759394A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2F6F7593A7F9355A FOREIGN KEY (quay_id) REFERENCES quay (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2F6F7593A7F9355A ON display_configuration (quay_id)');
        $this->addSql('CREATE INDEX idx_display_configuration_device ON display_configuration (device_id)');
        $this->addSql('CREATE TABLE quay (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, gtfs_stop_id VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, parent_stop_id VARCHAR(64) NOT NULL, destinations_served CLOB DEFAULT NULL, active BOOLEAN DEFAULT 1 NOT NULL, deprecated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_quay_parent_stop_id ON quay (parent_stop_id)');
        $this->addSql('CREATE INDEX idx_quay_active ON quay (active)');
        $this->addSql('CREATE UNIQUE INDEX uniq_quay_gtfs_stop_id ON quay (gtfs_stop_id)');
        $this->addSql('CREATE TABLE quay_route (quay_id INTEGER NOT NULL, route_id INTEGER NOT NULL, PRIMARY KEY (quay_id, route_id), CONSTRAINT FK_1CBA58D1A7F9355A FOREIGN KEY (quay_id) REFERENCES quay (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1CBA58D134ECB4E6 FOREIGN KEY (route_id) REFERENCES route (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_1CBA58D1A7F9355A ON quay_route (quay_id)');
        $this->addSql('CREATE INDEX IDX_1CBA58D134ECB4E6 ON quay_route (route_id)');
        $this->addSql('CREATE TABLE route (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, gtfs_route_id VARCHAR(64) NOT NULL, short_name VARCHAR(32) NOT NULL, long_name VARCHAR(255) DEFAULT NULL, color VARCHAR(6) NOT NULL, text_color VARCHAR(6) NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, deprecated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_route_gtfs_route_id ON route (gtfs_route_id)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE device');
        $this->addSql('DROP TABLE display_configuration');
        $this->addSql('DROP TABLE quay');
        $this->addSql('DROP TABLE quay_route');
        $this->addSql('DROP TABLE route');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
