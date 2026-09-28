<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928103500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow deliberately public Smart Collections while preserving private-by-default invariants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE collections DROP CONSTRAINT chk_collections_smart_rule'
        );
        $this->addSql(
            "ALTER TABLE collections
             ADD CONSTRAINT chk_collections_smart_rule
             CHECK (
                 (mode = 'manual' AND smart_rule IS NULL)
                 OR (
                     mode = 'smart'
                     AND owner_id IS NOT NULL
                     AND visibility IN ('private', 'public')
                     AND smart_rule IS NOT NULL
                     AND jsonb_typeof(smart_rule) = 'object'
                     AND (
                         visibility <> 'public'
                         OR (
                             password_protected = FALSE
                             AND password_reset_required = FALSE
                         )
                     )
                 )
             )"
        );
    }

    public function down(Schema $schema): void
    {
        // Downgrade fails closed for any Smart Collection that is still public.
        $this->addSql(
            "UPDATE collections
             SET visibility = 'private',
                 search_index_policy = 'noindex',
                 updated_at = CURRENT_TIMESTAMP
             WHERE mode = 'smart'
               AND visibility <> 'private'"
        );
        $this->addSql(
            'ALTER TABLE collections DROP CONSTRAINT chk_collections_smart_rule'
        );
        $this->addSql(
            "ALTER TABLE collections
             ADD CONSTRAINT chk_collections_smart_rule
             CHECK (
                 (mode = 'manual' AND smart_rule IS NULL)
                 OR (
                     mode = 'smart'
                     AND owner_id IS NOT NULL
                     AND visibility = 'private'
                     AND smart_rule IS NOT NULL
                     AND jsonb_typeof(smart_rule) = 'object'
                 )
             )"
        );
    }
}
