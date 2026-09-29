<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add collection-scoped child creation capability';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO permissions (permission_key, description)
             VALUES ('collection.create_child', 'Create a child collection within an allowed parent collection.')
             ON CONFLICT (permission_key) DO NOTHING"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM permissions WHERE permission_key = 'collection.create_child'");
    }
}
