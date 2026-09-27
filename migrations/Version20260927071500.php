<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927071500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent upload quota policies and session reservations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE user_storage_quotas (
    user_id UUID NOT NULL,
    limit_bytes BIGINT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(user_id),
    CONSTRAINT fk_user_storage_quota_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_user_storage_quota_limit CHECK (limit_bytes >= 0)
)
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE group_storage_quotas (
    group_id UUID NOT NULL,
    limit_bytes BIGINT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(group_id),
    CONSTRAINT fk_group_storage_quota_group
        FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE,
    CONSTRAINT chk_group_storage_quota_limit CHECK (limit_bytes >= 0)
)
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE upload_quota_reservations (
    upload_session_id UUID NOT NULL,
    user_id UUID NOT NULL,
    reserved_bytes BIGINT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(upload_session_id),
    CONSTRAINT fk_upload_quota_reservation_session
        FOREIGN KEY (upload_session_id)
        REFERENCES upload_sessions (id)
        ON DELETE CASCADE
        DEFERRABLE INITIALLY DEFERRED,
    CONSTRAINT fk_upload_quota_reservation_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_upload_quota_reserved_bytes CHECK (reserved_bytes >= 0)
)
SQL);
        $this->addSql(
            'CREATE INDEX idx_upload_quota_reservations_user ON upload_quota_reservations (user_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE upload_quota_reservations');
        $this->addSql('DROP TABLE group_storage_quotas');
        $this->addSql('DROP TABLE user_storage_quotas');
    }
}
