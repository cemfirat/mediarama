<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist deployment/publication settings and resource search-index policy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE platform_settings (
    id SMALLINT NOT NULL,
    deployment_profile VARCHAR(32) NOT NULL,
    public_publishing_enabled BOOLEAN NOT NULL,
    search_index_default VARCHAR(16) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY(id),
    CONSTRAINT chk_platform_settings_singleton CHECK (id = 1),
    CONSTRAINT chk_platform_deployment_profile CHECK (
        deployment_profile IN ('private_workspace', 'public_publishing', 'internal_isolated')
    ),
    CONSTRAINT chk_platform_search_index_default CHECK (
        search_index_default IN ('index', 'noindex')
    )
)
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO platform_settings (
    id,
    deployment_profile,
    public_publishing_enabled,
    search_index_default,
    created_at,
    updated_at
)
SELECT
    1,
    CASE
        WHEN EXISTS (SELECT 1 FROM effective_public_collections)
            THEN 'public_publishing'
        ELSE 'private_workspace'
    END,
    EXISTS (SELECT 1 FROM effective_public_collections),
    CASE
        WHEN EXISTS (SELECT 1 FROM effective_public_collections)
            THEN 'index'
        ELSE 'noindex'
    END,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
SQL);

        $this->addSql(
            "ALTER TABLE collections ADD search_index_policy VARCHAR(16) NOT NULL DEFAULT 'inherit'"
        );
        $this->addSql(
            "ALTER TABLE collections ADD CONSTRAINT chk_collection_search_index_policy
             CHECK (search_index_policy IN ('inherit', 'index', 'noindex'))"
        );

        $this->addSql(
            "ALTER TABLE media_assets ADD search_index_policy VARCHAR(16) NOT NULL DEFAULT 'inherit'"
        );
        $this->addSql(
            "ALTER TABLE media_assets ADD CONSTRAINT chk_media_search_index_policy
             CHECK (search_index_policy IN ('inherit', 'index', 'noindex'))"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_assets DROP CONSTRAINT chk_media_search_index_policy');
        $this->addSql('ALTER TABLE media_assets DROP COLUMN search_index_policy');
        $this->addSql('ALTER TABLE collections DROP CONSTRAINT chk_collection_search_index_policy');
        $this->addSql('ALTER TABLE collections DROP COLUMN search_index_policy');
        $this->addSql('DROP TABLE platform_settings');
    }
}
