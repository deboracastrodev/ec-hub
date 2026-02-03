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
        if (!file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $this->assertTrue(
            is_executable(self::SETUP_SCRIPT),
            'O script setup.sh deve ser executável (chmod +x)'
        );
    }

    public function test_setup_script_has_shebang(): void
    {
        if (!file_exists(self::SETUP_SCRIPT)) {
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
        if (!file_exists(self::SETUP_SCRIPT)) {
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
        if (!file_exists(self::SETUP_SCRIPT)) {
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

    public function test_setup_script_has_wait_for_redis_function(): void
    {
        if (!file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringContainsString(
            'wait_for_redis',
            $content,
            'O script deve ter função wait_for_redis'
        );
        $this->assertStringContainsString(
            'redis-cli ping',
            $content,
            'A função wait_for_redis deve usar redis-cli ping'
        );
    }

    public function test_setup_script_runs_composer_install(): void
    {
        if (!file_exists(self::SETUP_SCRIPT)) {
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
        if (!file_exists(self::SETUP_SCRIPT)) {
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
        if (!file_exists(self::SETUP_SCRIPT)) {
            $this->markTestSkipped('setup.sh não existe ainda');
        }

        $content = file_get_contents(self::SETUP_SCRIPT);
        $this->assertStringContainsString(
            'db:seed',
            $content,
            'O script deve executar seeders'
        );
    }

    public function test_setup_script_has_error_handling(): void
    {
        if (!file_exists(self::SETUP_SCRIPT)) {
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
        if (!file_exists(self::SETUP_SCRIPT)) {
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
