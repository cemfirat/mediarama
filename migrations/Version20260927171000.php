<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927171000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add durable cleanup queue for superseded and orphaned derivative objects';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE derivative_cleanup_jobs (
    id BIGSERIAL NOT NULL,
    storage_disk VARCHAR(80) NOT NULL,
    storage_key TEXT NOT NULL,
    reason VARCHAR(64) NOT NULL,
    media_id UUID DEFAULT NULL,
    kind VARCHAR(64) DEFAULT NULL,
    profile VARCHAR(120) DEFAULT NULL,
    processing_version INT DEFAULT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY(id),
    CONSTRAINT chk_derivative_cleanup_version
        CHECK (processing_version IS NULL OR processing_version >= 1)
)
SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_derivative_cleanup_storage
             ON derivative_cleanup_jobs (storage_disk, storage_key)'
        );
        $this->addSql(
            'CREATE INDEX idx_derivative_cleanup_created
             ON derivative_cleanup_jobs (created_at, id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE derivative_cleanup_jobs');
    }
}
