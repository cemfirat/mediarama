<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928084500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add explicit public publication timeline metadata to MediaAssets and Collections';
    }

    public function up(Schema $schema): void
    {
        foreach (['media_assets', 'collections'] as $table) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD public_published_at TIMESTAMPTZ DEFAULT NULL',
                $table,
            ));
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD public_updated_at TIMESTAMPTZ DEFAULT NULL',
                $table,
            ));
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD public_published_origin VARCHAR(16) DEFAULT NULL',
                $table,
            ));
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD public_published_source VARCHAR(255) DEFAULT NULL',
                $table,
            ));
            $this->addSql(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT chk_%s_publication_origin
                 CHECK (
                     (
                         public_published_at IS NULL
                         AND public_published_origin IS NULL
                         AND public_published_source IS NULL
                     )
                     OR (
                         public_published_at IS NOT NULL
                         AND public_published_origin IN ('editorial', 'imported')
                         AND (
                             (public_published_origin = 'editorial' AND public_published_source IS NULL)
                             OR (
                                 public_published_origin = 'imported'
                                 AND public_published_source IS NOT NULL
                                 AND btrim(public_published_source) <> ''
                             )
                         )
                     )
                 )",
                $table,
                $table,
            ));
            $this->addSql(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT chk_%s_public_timeline_order
                 CHECK (
                     public_updated_at IS NULL
                     OR public_published_at IS NULL
                     OR public_updated_at >= public_published_at
                 )",
                $table,
                $table,
            ));
            $this->addSql(sprintf(
                'CREATE INDEX idx_%s_public_published_at ON %s (public_published_at) WHERE public_published_at IS NOT NULL',
                $table,
                $table,
            ));
        }

        // Existing records deliberately remain unknown. Record creation/update
        // timestamps do not prove when a resource was intentionally published.
    }

    public function down(Schema $schema): void
    {
        foreach (['media_assets', 'collections'] as $table) {
            $this->addSql(sprintf('DROP INDEX idx_%s_public_published_at', $table));
            $this->addSql(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT chk_%s_public_timeline_order',
                $table,
                $table,
            ));
            $this->addSql(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT chk_%s_publication_origin',
                $table,
                $table,
            ));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN public_published_source', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN public_published_origin', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN public_updated_at', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN public_published_at', $table));
        }
    }
}
