<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Doctrine\DBAL\Schema\Schema;

final class Version20240826220000 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Migration to create justification_document and justification_document_rel_users tables with their foreign key relationships.';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        // Create justification_document table
        if (!$schemaManager->tablesExist(['justification_document'])) {
            $this->addSql('
                CREATE TABLE justification_document (
                    id INT AUTO_INCREMENT NOT NULL,
                    code LONGTEXT DEFAULT NULL,
                    name LONGTEXT DEFAULT NULL,
                    validity_duration INT DEFAULT NULL,
                    comment LONGTEXT DEFAULT NULL,
                    date_manual_on INT DEFAULT NULL,
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC;
            ');
        }

        // Create justification_document_rel_users table
        if (!$schemaManager->tablesExist(['justification_document_rel_users'])) {
            $this->addSql('
                CREATE TABLE justification_document_rel_users (
                    id INT AUTO_INCREMENT NOT NULL,
                    justification_document_id INT DEFAULT NULL,
                    user_id INT DEFAULT NULL,
                    file_path VARCHAR(255) DEFAULT NULL,
                    date_validity DATE DEFAULT NULL,
                    INDEX IDX_D1BB19421F2B6144 (justification_document_id),
                    INDEX IDX_D1BB1942A76ED395 (user_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC;
            ');
        }

        // Add foreign key constraints
        $foreignKeys = $schemaManager->listTableForeignKeys('justification_document_rel_users');
        $foreignKeyNames = array_map(fn($fk) => $fk->getName(), $foreignKeys);

        if (!in_array('FK_D1BB19421F2B6144', $foreignKeyNames, true)) {
            $this->addSql('
                ALTER TABLE justification_document_rel_users
                ADD CONSTRAINT FK_D1BB19421F2B6144
                FOREIGN KEY (justification_document_id)
                REFERENCES justification_document (id)
                ON DELETE CASCADE;
            ');
        }

        if (!in_array('FK_D1BB1942A76ED395', $foreignKeyNames, true)) {
            $this->addSql('
                ALTER TABLE justification_document_rel_users
                ADD CONSTRAINT FK_D1BB1942A76ED395
                FOREIGN KEY (user_id)
                REFERENCES user (id)
                ON DELETE CASCADE;
            ');
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        // Drop foreign key constraints
        if ($schemaManager->tablesExist(['justification_document_rel_users'])) {
            $foreignKeys = $schemaManager->listTableForeignKeys('justification_document_rel_users');
            $foreignKeyNames = array_map(fn($fk) => $fk->getName(), $foreignKeys);

            if (in_array('FK_D1BB19421F2B6144', $foreignKeyNames, true)) {
                $this->addSql('
                    ALTER TABLE justification_document_rel_users
                    DROP FOREIGN KEY FK_D1BB19421F2B6144;
                ');
            }

            if (in_array('FK_D1BB1942A76ED395', $foreignKeyNames, true)) {
                $this->addSql('
                    ALTER TABLE justification_document_rel_users
                    DROP FOREIGN KEY FK_D1BB1942A76ED395;
                ');
            }
        }

        // Drop justification_document_rel_users table
        if ($schemaManager->tablesExist(['justification_document_rel_users'])) {
            $this->addSql('DROP TABLE justification_document_rel_users;');
        }

        // Drop justification_document table
        if ($schemaManager->tablesExist(['justification_document'])) {
            $this->addSql('DROP TABLE justification_document;');
        }
    }
}
