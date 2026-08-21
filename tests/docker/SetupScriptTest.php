<?php

declare(strict_types=1);

namespace Tests\docker;

use PHPUnit\Framework\TestCase;

/**
 * Testes para o script setup.sh
 * Valida que o script existe, é executável e tem as funcionalidades necessárias
 */
class SetupScriptTest extends TestCase
{
    private const SETUP_SCRIPT = __DIR__ . '/../../setup.sh';

    public function test_setup_script_exists(): void
    {
        $this->assertFileExists(
            self::SETUP_SCRIPT,
            'O script setup.sh deve existir na raiz do projeto'
        );
    }

    public function test_setup_script_is_executable(): void
    {
        if (! file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $this->assertTrue(
            is_executable(self::SETUP_SCRIPT),
            'O script setup.sh deve ser executável (chmod +x)'
        );
    }

    public function test_setup_script_has_shebang(): void
    {
        if (! file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringStartsWith(
            '#!/bin/bash',
            $content,
            'O script deve começar com shebang #!/bin/bash'
        );
    }

    public function test_setup_script_has_docker_check(): void
    {
        if (! file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringContainsString(
            'docker info',
            $content,
            'O script deve verificar se Docker está rodando'
        );
    }

    public function test_setup_script_has_wait_for_mysql_function(): void
    {
        if (! file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringContainsString(
            'wait_for_mysql',
            $content,
            'O script deve ter função wait_for_mysql'
        );
        $this->assertStringContainsString(
            'mysql -uroot -psecret',
            $content,
            'A função wait_for_mysql deve usar as credenciais corretas'
        );
    }

    public function test_setup_script_waits_for_redis(): void
    {
        if (! file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringContainsString(
            'wait_for_redis',
            $content,
            'O script deve esperar por Redis antes de migrar ou popular o banco'
        );
        $this->assertStringContainsString('redis-cli ping', $content);
    }

    public function test_setup_stops_before_migrations_when_redis_is_unavailable(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . '/ec-hub-setup-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryDirectory, 0700));

        $dockerLog = $temporaryDirectory . '/docker.log';
        $fakeDocker = $temporaryDirectory . '/docker';

        try {
            file_put_contents($fakeDocker, <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$SETUP_DOCKER_LOG"

if [ "$1" = "info" ]; then
    exit 0
fi

if [ "$1" = "compose" ] && [ "$2" = "ps" ]; then
    printf 'app\n'
    exit 0
fi

if [ "$1" = "compose" ] && [ "$2" = "exec" ]; then
    case " $* " in
        *" redis "*) exit 1 ;;
        *) exit 0 ;;
    esac
fi

exit 1
SH);
            chmod($fakeDocker, 0700);

            $path = getenv('PATH') ?: '';
            $command = sprintf(
                'PATH=%s SETUP_MAX_ATTEMPTS=2 SETUP_RETRY_INTERVAL_SECONDS=0 SETUP_DOCKER_LOG=%s %s 2>&1',
                escapeshellarg($temporaryDirectory . ':' . $path),
                escapeshellarg($dockerLog),
                escapeshellarg(self::SETUP_SCRIPT)
            );
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('Redis não está pronto', implode("\n", $output));

            $commands = file_get_contents($dockerLog);
            self::assertIsString($commands);
            self::assertStringContainsString('compose exec -T redis redis-cli ping', $commands);
            self::assertStringNotContainsString('php bin/migrate.php', $commands);
            self::assertStringNotContainsString('php bin/seed.php', $commands);
        } finally {
            unlink($fakeDocker);
            if (file_exists($dockerLog)) {
                unlink($dockerLog);
            }
            rmdir($temporaryDirectory);
        }
    }

    public function test_setup_script_runs_composer_install(): void
    {
        if (! file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringContainsString(
            'composer install',
            $content,
            'O script deve executar composer install'
        );
    }

    public function test_setup_script_runs_migrations(): void
    {
        if (! file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringContainsString(
            'migrate',
            $content,
            'O script deve executar migrations do Hyperf'
        );
    }

    public function test_setup_script_runs_seeders(): void
    {
        if (! file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringContainsString(
            'php bin/seed.php',
            $content,
            'O script deve executar seeders'
        );
    }

    public function test_setup_script_has_error_handling(): void
    {
        if (! file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringContainsString(
            'set -e',
            $content,
            'O script deve ter "set -e" para exit on error'
        );
    }

    public function test_setup_script_has_success_message(): void
    {
        if (! file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringContainsString(
            'localhost:9501',
            $content,
            'O script deve exibir mensagem com localhost:9501'
        );
    }
}
