<?php

declare(strict_types=1);

namespace Tests\docker;

use PHPUnit\Framework\TestCase;

/**
 * Testes para o Makefile
 * Valida que os comandos especificados existem e funcionam
 */
class MakefileTest extends TestCase
{
    private const MAKEFILE = __DIR__ . '/../../Makefile';

    public function test_makefile_exists(): void
    {
        $this->assertFileExists(
            self::MAKEFILE,
            'O Makefile deve existir na raiz do projeto'
        );
    }

    public function test_makefile_has_up_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('up:', $content, 'O Makefile deve ter target "up"');
        $this->assertStringContainsString('$(COMPOSE)', $content, 'O Makefile deve usar variável COMPOSE');
        $this->assertStringContainsString('up -d', $content, 'O target "up" deve executar "up -d"');
    }

    public function test_makefile_has_down_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('down:', $content, 'O Makefile deve ter target "down"');
        $this->assertStringContainsString('$(COMPOSE)', $content, 'O Makefile deve usar variável COMPOSE');
        // Verifica que há uma linha com $(COMPOSE) down
        $this->assertRegExp('/\$\(COMPOSE\)\s+down/', $content, 'O target "down" deve executar "docker-compose down"');
    }

    public function test_makefile_has_restart_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('restart:', $content, 'O Makefile deve ter target "restart"');
        $this->assertStringContainsString('restart', $content, 'O target "restart" deve executar "docker-compose restart"');
    }

    public function test_makefile_has_logs_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('logs:', $content, 'O Makefile deve ter target "logs"');
        $this->assertStringContainsString('logs -f app', $content, 'O target "logs" deve executar "docker-compose logs -f app"');
    }

    public function test_makefile_has_test_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('test:', $content, 'O Makefile deve ter target "test"');
        $this->assertStringContainsString('phpunit', $content, 'O target "test" deve executar PHPUnit');
    }

    public function test_makefile_has_cs_fix_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('cs-fix:', $content, 'O Makefile deve ter target "cs-fix"');
        $this->assertStringContainsString('php-cs-fixer fix', $content, 'O target "cs-fix" deve executar PHP-CS-Fixer');
    }

    public function test_makefile_has_shell_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('shell:', $content, 'O Makefile deve ter target "shell"');
        $this->assertStringContainsString('exec app bash', $content, 'O target "shell" deve executar "docker-compose exec app bash"');
    }

    public function test_makefile_has_setup_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('setup:', $content, 'O Makefile deve ter target "setup"');
        $this->assertStringContainsString('./setup.sh', $content, 'O target "setup" deve executar ./setup.sh');
    }

    public function test_makefile_has_db_shell_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('db-shell:', $content, 'O Makefile deve ter target "db-shell"');
        $this->assertStringContainsString('mysql -uroot -psecret ec_hub', $content, 'O target "db-shell" deve acessar MySQL com credenciais corretas');
    }

    public function test_makefile_has_redis_cli_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('redis-cli:', $content, 'O Makefile deve ter target "redis-cli"');
        $this->assertStringContainsString('redis redis-cli', $content, 'O target "redis-cli" deve executar redis-cli');
    }

    public function test_makefile_has_ps_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('ps:', $content, 'O Makefile deve ter target "ps"');
        $this->assertStringContainsString('$(COMPOSE)', $content, 'O Makefile deve usar variável COMPOSE');
        // Verifica que há uma linha com $(COMPOSE) ps
        $this->assertRegExp('/\$\(COMPOSE\)\s+ps/', $content, 'O target "ps" deve executar "docker-compose ps"');
    }

    public function test_makefile_has_build_target(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('build:', $content, 'O Makefile deve ter target "build"');
        $this->assertStringContainsString('build --no-cache', $content, 'O target "build" deve executar "docker-compose build --no-cache"');
    }

    public function test_makefile_targets_use_docker_compose_exec(): void
    {
        $content = file_get_contents(self::MAKEFILE);

        // Verificar que os comandos de dev usam docker-compose exec
        $this->assertStringContainsString('exec app vendor/bin/phpunit', $content,
            'O target "test" deve usar docker-compose exec para executar PHPUnit');

        $this->assertStringContainsString('exec app vendor/bin/php-cs-fixer fix', $content,
            'O target "cs-fix" deve usar docker-compose exec para executar PHP-CS-Fixer');
    }

    public function test_makefile_has_phony_declaration(): void
    {
        $content = file_get_contents(self::MAKEFILE);
        $this->assertStringContainsString('.PHONY:', $content, 'O Makefile deve ter declaração .PHONY');
    }
}
