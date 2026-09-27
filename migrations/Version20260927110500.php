<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927110500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index media-to-collection membership lookups for authorization-aware search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE INDEX idx_collection_media_media_collection
             ON collection_media (media_id, collection_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_collection_media_media_collection');
    }
}
