<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope resumable import state to a stable source instance and remove the unused run mapping table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_runs ADD source_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_import_runs_source_key ON import_runs (source_key)');

        $this->addSql('ALTER TABLE import_mappings RENAME COLUMN source TO source_key');
        $this->addSql('ALTER TABLE import_checkpoints RENAME COLUMN source TO source_key');

        $this->addSql('DROP TABLE import_id_map');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE import_id_map (
    import_run_id UUID NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    source_id VARCHAR(255) NOT NULL,
    target_id UUID NOT NULL,
    PRIMARY KEY(import_run_id, entity_type, source_id),
    CONSTRAINT fk_import_map_run FOREIGN KEY (import_run_id) REFERENCES import_runs (id) ON DELETE CASCADE
)
SQL);

        $this->addSql('ALTER TABLE import_checkpoints RENAME COLUMN source_key TO source');
        $this->addSql('ALTER TABLE import_mappings RENAME COLUMN source_key TO source');

        $this->addSql('DROP INDEX idx_import_runs_source_key');
        $this->addSql('ALTER TABLE import_runs DROP COLUMN source_key');
    }
}
