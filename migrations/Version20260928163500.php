<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928163500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add auditable approval-gated AI organization preflights';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE organization_ai_preflights (
    id UUID NOT NULL,
    requester_id UUID NOT NULL,
    provider_key VARCHAR(80) NOT NULL,
    producer_kind VARCHAR(32) NOT NULL,
    provider_name VARCHAR(160) NOT NULL,
    model_name VARCHAR(255) NOT NULL,
    model_version VARCHAR(255) DEFAULT NULL,
    requested_capabilities JSONB NOT NULL,
    input_mode VARCHAR(40) NOT NULL,
    media_type_counts JSONB NOT NULL,
    media_count INT NOT NULL,
    presentation_media_count INT NOT NULL,
    include_creator BOOLEAN NOT NULL,
    include_location_name BOOLEAN NOT NULL,
    excluded_fields JSONB NOT NULL,
    cost_estimate TEXT DEFAULT NULL,
    privacy_note TEXT DEFAULT NULL,
    retention_note TEXT DEFAULT NULL,
    status VARCHAR(32) NOT NULL,
    run_id UUID DEFAULT NULL,
    failure_code VARCHAR(64) DEFAULT NULL,
    approved_at TIMESTAMPTZ DEFAULT NULL,
    started_at TIMESTAMPTZ DEFAULT NULL,
    completed_at TIMESTAMPTZ DEFAULT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY(id),
    CONSTRAINT fk_organization_ai_preflight_requester
        FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_organization_ai_preflight_run
        FOREIGN KEY (run_id) REFERENCES organization_runs (id) ON DELETE CASCADE,
    CONSTRAINT chk_organization_ai_preflight_producer
        CHECK (producer_kind IN ('ai_external', 'ai_local')),
    CONSTRAINT chk_organization_ai_preflight_capabilities
        CHECK (
            jsonb_typeof(requested_capabilities) = 'array'
            AND jsonb_array_length(requested_capabilities) > 0
        ),
    CONSTRAINT chk_organization_ai_preflight_input_mode
        CHECK (input_mode IN ('metadata_only', 'metadata_and_presentation')),
    CONSTRAINT chk_organization_ai_preflight_media_counts
        CHECK (
            media_count >= 1
            AND media_count <= 50000
            AND presentation_media_count >= 0
            AND presentation_media_count <= media_count
            AND jsonb_typeof(media_type_counts) = 'object'
        ),
    CONSTRAINT chk_organization_ai_preflight_excluded
        CHECK (jsonb_typeof(excluded_fields) = 'array'),
    CONSTRAINT chk_organization_ai_preflight_status
        CHECK (
            status IN (
                'pending_approval',
                'approved',
                'executing',
                'completed',
                'failed',
                'cancelled'
            )
        ),
    CONSTRAINT chk_organization_ai_preflight_failure
        CHECK (
            (status = 'failed' AND failure_code IS NOT NULL)
            OR (status <> 'failed' AND failure_code IS NULL)
        ),
    CONSTRAINT chk_organization_ai_preflight_run
        CHECK (
            (status = 'completed' AND run_id IS NOT NULL)
            OR (status <> 'completed' AND run_id IS NULL)
        )
)
SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_organization_ai_preflight_run
             ON organization_ai_preflights (run_id)
             WHERE run_id IS NOT NULL'
        );
        $this->addSql(
            'CREATE INDEX idx_organization_ai_preflight_requester
             ON organization_ai_preflights (requester_id, created_at DESC)'
        );

        $this->addSql(<<<'SQL'
CREATE TABLE organization_ai_preflight_media (
    preflight_id UUID NOT NULL,
    media_id UUID NOT NULL,
    position INT NOT NULL,
    send_presentation BOOLEAN NOT NULL,
    PRIMARY KEY(preflight_id, media_id),
    CONSTRAINT fk_organization_ai_preflight_media_preflight
        FOREIGN KEY (preflight_id)
        REFERENCES organization_ai_preflights (id) ON DELETE CASCADE,
    CONSTRAINT fk_organization_ai_preflight_media_media
        FOREIGN KEY (media_id)
        REFERENCES media_assets (id) ON DELETE CASCADE,
    CONSTRAINT chk_organization_ai_preflight_media_position
        CHECK (position >= 0),
    CONSTRAINT uniq_organization_ai_preflight_media_position
        UNIQUE (preflight_id, position)
)
SQL);
        $this->addSql(
            'CREATE INDEX idx_organization_ai_preflight_media_media
             ON organization_ai_preflight_media (media_id, preflight_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE organization_ai_preflight_media');
        $this->addSql('DROP TABLE organization_ai_preflights');
    }
}
