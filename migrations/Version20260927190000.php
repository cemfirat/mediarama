<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add retryable storage cleanup queue for superseded derivatives';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE storage_cleanup_jobs (
    id UUID NOT NULL,
    storage_disk VARCHAR(80) NOT NULL,
    storage_key TEXT NOT NULL,
    reason VARCHAR(64) NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    claimed_at TIMESTAMPTZ DEFAULT NULL,
    last_error TEXT DEFAULT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY(id),
    CONSTRAINT chk_storage_cleanup_status CHECK (status IN ('pending', 'processing')),
    CONSTRAINT chk_storage_cleanup_attempts CHECK (attempts >= 0)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_storage_cleanup_object ON storage_cleanup_jobs (storage_disk, storage_key)');
        $this->addSql('CREATE INDEX idx_storage_cleanup_status_created ON storage_cleanup_jobs (status, created_at)');
        $this->addSql('CREATE INDEX idx_storage_cleanup_claimed ON storage_cleanup_jobs (claimed_at) WHERE claimed_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE storage_cleanup_jobs');
    }
}
