<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928131500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add explicit completed-without-suggestions organization run state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE organization_runs
             DROP CONSTRAINT chk_organization_run_status'
        );
        $this->addSql(
            "ALTER TABLE organization_runs
             ADD CONSTRAINT chk_organization_run_status
             CHECK (
                 status IN (
                     'draft',
                     'ready_for_review',
                     'no_suggestions',
                     'failed',
                     'cancelled'
                 )
             )"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE organization_runs
             SET status = 'cancelled',
                 updated_at = CURRENT_TIMESTAMP
             WHERE status = 'no_suggestions'"
        );
        $this->addSql(
            'ALTER TABLE organization_runs
             DROP CONSTRAINT chk_organization_run_status'
        );
        $this->addSql(
            "ALTER TABLE organization_runs
             ADD CONSTRAINT chk_organization_run_status
             CHECK (
                 status IN (
                     'draft',
                     'ready_for_review',
                     'failed',
                     'cancelled'
                 )
             )"
        );
    }
}
