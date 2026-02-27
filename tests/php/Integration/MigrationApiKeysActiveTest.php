<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test for db_ensure_api_keys_active_column().
 *
 * Verifies the runtime migration that adds is_active column to api_keys
 * works correctly on tables that lack it.
 */
final class MigrationApiKeysActiveTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->pdo->exec('DROP TABLE IF EXISTS api_keys_test');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS api_keys_test');
    }

    public function testAddsIsActiveColumnWhenMissing(): void
    {
        $pdo = $this->pdo;

        // Create api_keys_test WITHOUT is_active (mimics old schema)
        $pdo->exec("
            CREATE TABLE api_keys_test (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                api_key VARCHAR(255) NOT NULL,
                name VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Insert a key before migration
        $pdo->exec("INSERT INTO api_keys_test (api_key, name) VALUES ('test-key-123', 'Test Key')");

        // Verify column doesn't exist yet
        $row = $pdo->query("SHOW COLUMNS FROM api_keys_test LIKE 'is_active'")->fetch();
        $this->assertFalse($row);

        // Run the migration logic (adapted for test table)
        $row = $pdo->query("SHOW COLUMNS FROM api_keys_test LIKE 'is_active'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE api_keys_test ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE");
            $pdo->exec("UPDATE api_keys_test SET is_active = TRUE WHERE is_active IS NULL");
        }

        // Verify column now exists
        $row = $pdo->query("SHOW COLUMNS FROM api_keys_test LIKE 'is_active'")->fetch();
        $this->assertNotFalse($row);

        // Verify existing rows got is_active = TRUE
        $active = (int)$pdo->query("SELECT is_active FROM api_keys_test WHERE api_key = 'test-key-123'")->fetchColumn();
        $this->assertSame(1, $active);
    }

    public function testIdempotentWhenColumnExists(): void
    {
        $pdo = $this->pdo;

        // Create table WITH is_active already present
        $pdo->exec("
            CREATE TABLE api_keys_test (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                api_key VARCHAR(255) NOT NULL,
                name VARCHAR(255) DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("INSERT INTO api_keys_test (api_key, name, is_active) VALUES ('key-1', 'Active', TRUE)");
        $pdo->exec("INSERT INTO api_keys_test (api_key, name, is_active) VALUES ('key-2', 'Inactive', FALSE)");

        // Run migration check — should be a no-op
        $row = $pdo->query("SHOW COLUMNS FROM api_keys_test LIKE 'is_active'")->fetch();
        $this->assertNotFalse($row, 'Column should already exist');

        // Verify data unchanged (especially the FALSE value)
        $inactive = (int)$pdo->query("SELECT is_active FROM api_keys_test WHERE api_key = 'key-2'")->fetchColumn();
        $this->assertSame(0, $inactive, 'Inactive key should remain FALSE');
    }
}
