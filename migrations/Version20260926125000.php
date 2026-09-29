<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926125000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add historical/product view counters for media and collections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_assets ADD COLUMN view_count BIGINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE media_assets ADD CONSTRAINT chk_media_view_count CHECK (view_count >= 0)');
        $this->addSql('ALTER TABLE collections ADD COLUMN view_count BIGINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE collections ADD CONSTRAINT chk_collection_view_count CHECK (view_count >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections DROP CONSTRAINT chk_collection_view_count');
        $this->addSql('ALTER TABLE collections DROP COLUMN view_count');
        $this->addSql('ALTER TABLE media_assets DROP CONSTRAINT chk_media_view_count');
        $this->addSql('ALTER TABLE media_assets DROP COLUMN view_count');
    }
}
