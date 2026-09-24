<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\AdminCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminCredentialsTest extends TestCase
{
    public function testTrimsUsernameAndIsConfiguredWithARecognizedHash(): void
    {
        $credentials = AdminCredentials::fromArray([
            'username' => '  admin  ',
            'password_hash' => password_hash('s3nha', PASSWORD_DEFAULT),
        ]);

        self::assertSame('admin', $credentials->username);
        self::assertTrue($credentials->isConfigured());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unconfiguredProvider(): iterable
    {
        $hash = password_hash('s3nha', PASSWORD_DEFAULT);

        yield 'empty config' => [[]];
        yield 'blank username' => [['username' => '   ', 'password_hash' => $hash]];
        yield 'empty hash' => [['username' => 'admin', 'password_hash' => '']];
        yield 'plain password instead of hash' => [['username' => 'admin', 'password_hash' => 's3nha']];
        yield 'non-string values' => [['username' => ['admin'], 'password_hash' => 123]];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('unconfiguredProvider')]
    public function testFailsClosedWithoutValidCredentials(array $config): void
    {
        self::assertFalse(AdminCredentials::fromArray($config)->isConfigured());
    }

    public function testConfigFileReadsTheEnvironment(): void
    {
        $previousUser = getenv('ADMIN_USERNAME');
        $previousHash = getenv('ADMIN_PASSWORD_HASH');
        putenv('ADMIN_USERNAME=root-admin');
        putenv('ADMIN_PASSWORD_HASH=$2y$10$abc');

        try {
            $config = require dirname(__DIR__, 4) . '/config/admin.php';
            self::assertSame(['username' => 'root-admin', 'password_hash' => '$2y$10$abc'], $config);

            putenv('ADMIN_USERNAME');
            putenv('ADMIN_PASSWORD_HASH');
            $config = require dirname(__DIR__, 4) . '/config/admin.php';
            self::assertSame(['username' => '', 'password_hash' => ''], $config);
        } finally {
            putenv($previousUser === false ? 'ADMIN_USERNAME' : 'ADMIN_USERNAME=' . $previousUser);
            putenv($previousHash === false ? 'ADMIN_PASSWORD_HASH' : 'ADMIN_PASSWORD_HASH=' . $previousHash);
        }
    }
}
