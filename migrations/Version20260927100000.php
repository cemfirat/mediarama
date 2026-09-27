<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index active collection parent relationships for recursive access queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE INDEX idx_collections_parent_active
             ON collections (parent_id)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_collections_parent_active');
    }
}
