<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016145013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX sampling_point_hole_key_idx');
        $this->addSql('DROP INDEX sampling_point_dataset_hole_idx');
        $this->addSql('ALTER TABLE sampling_point DROP hole_key');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sampling_point ADD hole_key TEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX sampling_point_hole_key_idx ON sampling_point (hole_key)');
        $this->addSql('CREATE INDEX sampling_point_dataset_hole_idx ON sampling_point (dataset_id, hole_key)');
    }
}
