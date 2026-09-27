<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist sanitized observable upload failure metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE upload_sessions ADD last_failure_code VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE upload_sessions ADD last_failure_stage VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE upload_sessions ADD last_failure_retryable BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE upload_sessions ADD last_failed_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql(<<<'SQL'
ALTER TABLE upload_sessions
ADD CONSTRAINT chk_upload_session_failure_metadata CHECK (
    (
        last_failure_code IS NULL
        AND last_failure_stage IS NULL
        AND last_failure_retryable IS NULL
        AND last_failed_at IS NULL
    )
    OR
    (
        last_failure_code IS NOT NULL
        AND last_failure_stage IS NOT NULL
        AND last_failure_retryable IS NOT NULL
        AND last_failed_at IS NOT NULL
    )
)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE upload_sessions DROP CONSTRAINT chk_upload_session_failure_metadata');
        $this->addSql('ALTER TABLE upload_sessions DROP last_failed_at');
        $this->addSql('ALTER TABLE upload_sessions DROP last_failure_retryable');
        $this->addSql('ALTER TABLE upload_sessions DROP last_failure_stage');
        $this->addSql('ALTER TABLE upload_sessions DROP last_failure_code');
    }
}
