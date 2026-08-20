<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PDOException;

/**
 * Shared setup for tests that need a real MySQL connection.
 *
 * Classes using this trait must also carry #[Group('db')] so the suite can be
 * run with --exclude-group db when no database is available (see R2.5).
 */
trait RequiresDatabase
{
    /**
     * Connect to MySQL using the same env vars as config/bootstrap.php, or
     * skip the test cleanly instead of erroring when the database is down.
     */
    protected function connectToDatabaseOrSkip(): PDO
    {
        try {
            return new PDO(
                'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1')
                    . ';port=' . (getenv('DB_PORT') ?: '3306')
                    . ';dbname=' . (getenv('DB_DATABASE') ?: 'ec_hub'),
                getenv('DB_USERNAME') ?: 'root',
                getenv('DB_PASSWORD') ?: 'secret',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('MySQL indisponível: ' . $e->getMessage());
        }
    }
}
