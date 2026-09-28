<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928174500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record idempotent organization proposal application outcomes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE organization_proposals
             ADD COLUMN applied_resource_type VARCHAR(32) DEFAULT NULL,
             ADD COLUMN applied_resource_id UUID DEFAULT NULL'
        );
        $this->addSql(
            "ALTER TABLE organization_proposals
             ADD CONSTRAINT chk_organization_proposal_application
             CHECK (
                 (
                     status = 'applied'
                     AND applied_resource_type IN ('collection', 'media', 'tag')
                     AND applied_resource_id IS NOT NULL
                 )
                 OR (
                     status <> 'applied'
                     AND applied_resource_type IS NULL
                     AND applied_resource_id IS NULL
                 )
             )"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE organization_proposals
             DROP CONSTRAINT chk_organization_proposal_application'
        );
        $this->addSql(
            'ALTER TABLE organization_proposals
             DROP COLUMN applied_resource_type,
             DROP COLUMN applied_resource_id'
        );
    }
}
