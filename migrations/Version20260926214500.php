<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926214500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Centralize effective public collection visibility';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE VIEW effective_public_collections AS
WITH RECURSIVE public_tree AS (
    SELECT c.id
    FROM collections c
    WHERE c.parent_id IS NULL
      AND c.deleted_at IS NULL
      AND c.visibility = 'public'
      AND c.password_protected = FALSE
      AND c.password_reset_required = FALSE

    UNION ALL

    SELECT child.id
    FROM collections child
    JOIN public_tree parent ON parent.id = child.parent_id
    WHERE child.deleted_at IS NULL
      AND child.visibility = 'public'
      AND child.password_protected = FALSE
      AND child.password_reset_required = FALSE
)
SELECT id AS collection_id
FROM public_tree
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW effective_public_collections');
    }
}
