<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928181500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist idempotent organization proposal apply results';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE organization_proposals
             ADD applied_entity_type VARCHAR(32) DEFAULT NULL'
        );
        $this->addSql(
            'ALTER TABLE organization_proposals
             ADD applied_entity_id UUID DEFAULT NULL'
        );
        $this->addSql(
            "ALTER TABLE organization_proposals
             ADD CONSTRAINT chk_organization_proposal_apply_result
             CHECK (
                 (
                     status = 'applied'
                     AND applied_entity_type IN ('collection', 'tag', 'media')
                     AND applied_entity_id IS NOT NULL
                 )
                 OR (
                     status <> 'applied'
                     AND applied_entity_type IS NULL
                     AND applied_entity_id IS NULL
                 )
             )"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE organization_proposals
             DROP CONSTRAINT chk_organization_proposal_apply_result'
        );
        $this->addSql(
            'ALTER TABLE organization_proposals DROP applied_entity_id'
        );
        $this->addSql(
            'ALTER TABLE organization_proposals DROP applied_entity_type'
        );
    }
}
