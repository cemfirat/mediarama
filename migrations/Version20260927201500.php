<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927201500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist first-run setup state and link completed setup to the initial administrator';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE platform_settings ADD setup_status VARCHAR(16) NOT NULL DEFAULT 'pending'");
        $this->addSql("ALTER TABLE platform_settings ADD setup_completed_at TIMESTAMPTZ DEFAULT NULL");
        $this->addSql("ALTER TABLE platform_settings ADD setup_completed_by UUID DEFAULT NULL");
        $this->addSql("ALTER TABLE platform_settings ADD setup_completed_via VARCHAR(24) DEFAULT NULL");
        $this->addSql(
            "ALTER TABLE platform_settings ADD CONSTRAINT chk_platform_setup_status CHECK (setup_status IN ('pending', 'completed'))"
        );
        $this->addSql(
            "ALTER TABLE platform_settings ADD CONSTRAINT chk_platform_setup_completed_via
             CHECK (
                 setup_completed_via IS NULL
                 OR setup_completed_via IN ('migration', 'browser', 'cli', 'existing_admin')
             )"
        );
        $this->addSql(
            'ALTER TABLE platform_settings
             ADD CONSTRAINT fk_platform_setup_completed_by
             FOREIGN KEY (setup_completed_by) REFERENCES users (id) ON DELETE SET NULL'
        );
        $this->addSql(
            "ALTER TABLE platform_settings
             ADD CONSTRAINT chk_platform_setup_completion
             CHECK (
                 (
                     setup_status = 'pending'
                     AND setup_completed_at IS NULL
                     AND setup_completed_by IS NULL
                     AND setup_completed_via IS NULL
                 )
                 OR (
                     setup_status = 'completed'
                     AND setup_completed_at IS NOT NULL
                     AND setup_completed_via IS NOT NULL
                 )
             )"
        );

        // Existing installations that already have an active system administrator
        // are initialized immediately. Empty/new installations remain pending.
        $this->addSql(<<<'SQL'
WITH existing_admin AS (
    SELECT DISTINCT u.id, u.created_at
    FROM users u
    INNER JOIN user_groups ug ON ug.user_id = u.id
    INNER JOIN group_permissions gp ON gp.group_id = ug.group_id
    WHERE u.status = 'active'
      AND gp.permission_key = 'system.admin'
    ORDER BY u.created_at ASC, u.id ASC
    LIMIT 1
)
UPDATE platform_settings ps
SET setup_status = 'completed',
    setup_completed_at = CURRENT_TIMESTAMP,
    setup_completed_by = existing_admin.id,
    setup_completed_via = 'migration',
    updated_at = CURRENT_TIMESTAMP
FROM existing_admin
WHERE ps.id = 1
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE platform_settings DROP CONSTRAINT chk_platform_setup_completion');
        $this->addSql('ALTER TABLE platform_settings DROP CONSTRAINT fk_platform_setup_completed_by');
        $this->addSql('ALTER TABLE platform_settings DROP CONSTRAINT chk_platform_setup_completed_via');
        $this->addSql('ALTER TABLE platform_settings DROP CONSTRAINT chk_platform_setup_status');
        $this->addSql('ALTER TABLE platform_settings DROP COLUMN setup_completed_via');
        $this->addSql('ALTER TABLE platform_settings DROP COLUMN setup_completed_by');
        $this->addSql('ALTER TABLE platform_settings DROP COLUMN setup_completed_at');
        $this->addSql('ALTER TABLE platform_settings DROP COLUMN setup_status');
    }
}
