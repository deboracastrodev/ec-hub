<?php

declare(strict_types=1);

namespace Tests\Integration\Tooling;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DW-8: bin/migrate.php só cria idx_products_slug depois do backfill, então
 * tabelas legadas com várias linhas (e as deixadas quebradas pela ordem
 * antiga) convergem para o mesmo schema de um banco novo.
 */
#[Group('db')]
final class MigrateScriptTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const SCRIPT = self::ROOT . '/bin/migrate.php';
    private const DATABASE = 'ec_hub_migrate_test';

    private PDO $pdo;

    protected function setUp(): void
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        try {
            $server = new PDO("mysql:host={$this->host()};port={$this->port()};charset=utf8mb4", $this->username(), $this->password(), $options);
            $server->exec('DROP DATABASE IF EXISTS ' . self::DATABASE);
            $server->exec('CREATE DATABASE ' . self::DATABASE . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $exception) {
            $this->markTestSkipped('MySQL indisponível para ' . self::DATABASE . ': ' . $exception->getMessage());
        }

        $this->pdo = new PDO(
            "mysql:host={$this->host()};port={$this->port()};dbname=" . self::DATABASE . ';charset=utf8mb4',
            $this->username(),
            $this->password(),
            $options + [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP DATABASE IF EXISTS ' . self::DATABASE);
        }
    }

    public function test_legacy_table_with_duplicate_names_gets_unique_slugs_and_index(): void
    {
        $this->createLegacyProductsTable(withSlug: false);
        $this->seedLegacyRows(withSlug: false);

        $this->assertMigrationSucceeds();

        $this->assertSame(
            ['notebook-pro', 'notebook-pro-1', 'teclado-mecanico'],
            $this->slugsById()
        );
        $this->assertFinalSlugSchema();
    }

    public function test_rerun_on_migrated_database_changes_nothing(): void
    {
        $this->createLegacyProductsTable(withSlug: false);
        $this->seedLegacyRows(withSlug: false);
        $this->assertMigrationSucceeds();
        $slugsAfterFirstRun = $this->slugsById();
        $indexesAfterFirstRun = $this->indexDefinitions();

        $output = $this->assertMigrationSucceeds();

        $this->assertStringNotContainsString("Adicionando coluna 'slug'", $output);
        $this->assertSame($slugsAfterFirstRun, $this->slugsById());
        $this->assertSame($indexesAfterFirstRun, $this->indexDefinitions());
        $this->assertFinalSlugSchema();
    }

    public function test_empty_database_gets_full_schema(): void
    {
        $this->assertMigrationSucceeds();

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $this->assertSame(['order_items', 'orders', 'products'], $tables);
        $this->assertFinalSlugSchema();
    }

    public function test_database_left_broken_by_old_ordering_is_recovered(): void
    {
        // Estado da versão antiga: ADD COLUMN slug NOT NULL rodou (todas as
        // linhas ficaram com '') e o CREATE UNIQUE INDEX abortou.
        $this->createLegacyProductsTable(withSlug: true);
        $this->seedLegacyRows(withSlug: true);

        $this->assertMigrationSucceeds();

        $this->assertSame(
            ['notebook-pro', 'notebook-pro-1', 'teclado-mecanico'],
            $this->slugsById()
        );
        $this->assertFinalSlugSchema();
    }

    public function test_nullable_slug_left_by_interrupted_run_is_made_not_null(): void
    {
        // Estado de uma execução nova interrompida entre o ADD COLUMN nullable
        // e o MODIFY: a coluna existe, aceita NULL e não tem índice.
        $this->createLegacyProductsTable(withSlug: true, slugNullable: true);
        $this->pdo->exec("INSERT INTO products (name, description, price, category) VALUES ('Notebook Pro', 'x', 10.00, 'Informatica'), ('Notebook Pro', 'x', 10.00, 'Informatica')");

        $this->assertMigrationSucceeds();

        $this->assertSame(['notebook-pro', 'notebook-pro-1'], $this->slugsById());
        $this->assertFinalSlugSchema();
    }

    private function createLegacyProductsTable(bool $withSlug, bool $slugNullable = false): void
    {
        $slugColumn = $withSlug ? 'slug VARCHAR(255) ' . ($slugNullable ? 'NULL' : 'NOT NULL') . ',' : '';

        $this->pdo->exec(<<<SQL
            CREATE TABLE products (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                price DECIMAL(10, 2) NOT NULL,
                category VARCHAR(100) NOT NULL,
                {$slugColumn}
                image_url VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_products_name (name),
                INDEX idx_products_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function seedLegacyRows(bool $withSlug): void
    {
        $sql = $withSlug
            ? "INSERT INTO products (name, description, price, category, slug) VALUES (:name, 'x', 10.00, 'Informatica', '')"
            : "INSERT INTO products (name, description, price, category) VALUES (:name, 'x', 10.00, 'Informatica')";
        $insert = $this->pdo->prepare($sql);

        foreach (['Notebook Pro', 'Notebook Pro', 'Teclado Mecanico'] as $name) {
            $insert->execute(['name' => $name]);
        }
    }

    private function assertMigrationSucceeds(): string
    {
        [$exitCode, $stdout, $stderr] = $this->runMigrate();

        $this->assertSame(0, $exitCode, "migrate.php falhou:\n{$stdout}\n{$stderr}");

        return $stdout;
    }

    private function assertFinalSlugSchema(): void
    {
        $slugColumn = $this->pdo->query("SHOW COLUMNS FROM products LIKE 'slug'")->fetch();
        $this->assertIsArray($slugColumn);
        $this->assertSame('NO', $slugColumn['Null']);
        $this->assertSame('varchar(255)', strtolower((string) $slugColumn['Type']));

        $index = $this->pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_products_slug'")->fetchAll();
        $this->assertCount(1, $index);
        $this->assertSame('slug', $index[0]['Column_name']);
        $this->assertSame(0, (int) $index[0]['Non_unique']);

        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM products WHERE slug IS NULL OR slug = ''")->fetchColumn()
        );
    }

    /**
     * Só a definição dos índices; Cardinality é estimativa do InnoDB e oscila.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function indexDefinitions(): array
    {
        return array_map(
            static fn (array $row): array => [$row['Key_name'], $row['Column_name'], (int) $row['Non_unique'], (int) $row['Seq_in_index']],
            $this->pdo->query('SHOW INDEX FROM products')->fetchAll()
        );
    }

    /** @return list<string> */
    private function slugsById(): array
    {
        return $this->pdo->query('SELECT slug FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runMigrate(): array
    {
        $process = proc_open(
            [PHP_BINARY, self::SCRIPT],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::ROOT,
            array_merge(getenv(), [
                'DB_HOST' => $this->host(),
                'DB_PORT' => $this->port(),
                'DB_DATABASE' => self::DATABASE,
                'DB_USERNAME' => $this->username(),
                'DB_PASSWORD' => $this->password(),
            ])
        );
        $this->assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function host(): string
    {
        return getenv('DB_HOST') ?: '127.0.0.1';
    }

    private function port(): string
    {
        return getenv('DB_PORT') ?: '3306';
    }

    private function username(): string
    {
        return getenv('DB_USERNAME') ?: 'root';
    }

    private function password(): string
    {
        return getenv('DB_PASSWORD') ?: 'secret';
    }
}
