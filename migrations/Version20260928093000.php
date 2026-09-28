<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add explicit manual/smart Collection mode and persisted validated Smart rule';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE collections ADD mode VARCHAR(16) NOT NULL DEFAULT 'manual'");
        $this->addSql("ALTER TABLE collections ADD smart_rule JSONB DEFAULT NULL");
        $this->addSql(
            "ALTER TABLE collections
             ADD CONSTRAINT chk_collections_mode
             CHECK (mode IN ('manual', 'smart'))"
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
        $this->addSql(
            "CREATE INDEX idx_collections_smart_owner
             ON collections (owner_id, id)
             WHERE mode = 'smart'"
        );

        $this->addSql(<<<'SQL'
CREATE FUNCTION mediarama_require_manual_collection_membership()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM collections c
        WHERE c.id = NEW.collection_id
          AND c.mode <> 'manual'
    ) THEN
        RAISE EXCEPTION 'Persisted collection_media membership requires a manual Collection.';
    END IF;

    RETURN NEW;
END;
$$
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_collection_media_requires_manual_collection
BEFORE INSERT OR UPDATE OF collection_id ON collection_media
FOR EACH ROW
EXECUTE FUNCTION mediarama_require_manual_collection_membership()
SQL);

        $this->addSql(<<<'SQL'
CREATE FUNCTION mediarama_require_empty_smart_collection()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.mode = 'smart'
       AND OLD.mode IS DISTINCT FROM NEW.mode
       AND EXISTS (
           SELECT 1
           FROM collection_media cm
           WHERE cm.collection_id = NEW.id
       )
    THEN
        RAISE EXCEPTION 'A Collection with persisted membership cannot enter Smart mode.';
    END IF;

    RETURN NEW;
END;
$$
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_collection_smart_mode_requires_no_membership
BEFORE UPDATE OF mode ON collections
FOR EACH ROW
EXECUTE FUNCTION mediarama_require_empty_smart_collection()
SQL);

        // Existing and imported Collections deliberately remain manual.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_collection_smart_mode_requires_no_membership ON collections');
        $this->addSql('DROP FUNCTION mediarama_require_empty_smart_collection');
        $this->addSql('DROP TRIGGER trg_collection_media_requires_manual_collection ON collection_media');
        $this->addSql('DROP FUNCTION mediarama_require_manual_collection_membership');
        $this->addSql('DROP INDEX idx_collections_smart_owner');
        $this->addSql('ALTER TABLE collections DROP CONSTRAINT chk_collections_smart_rule');
        $this->addSql('ALTER TABLE collections DROP CONSTRAINT chk_collections_mode');
        $this->addSql('ALTER TABLE collections DROP COLUMN smart_rule');
        $this->addSql('ALTER TABLE collections DROP COLUMN mode');
    }
}
