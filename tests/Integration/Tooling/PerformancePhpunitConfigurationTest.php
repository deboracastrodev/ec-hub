<?php

declare(strict_types=1);

namespace Tests\Integration\Tooling;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;

/**
 * A suíte de performance tem configuração própria (tests/Performance/phpunit.xml)
 * só para ficar fora de make test, test-no-db e test-full; o ambiente de teste
 * é o mesmo da phpunit.xml raiz. Quando a raiz ganhou LOG_LEVEL=none (Story
 * 10.4) e a outra não, o MemoryGrowthTest -- processo isolado -- passou a logar
 * cada recomendação no stderr do filho, que o PHPUnit só lê depois do stdout:
 * o pipe encheu e `make test-performance` travou o job de CI.
 */
final class PerformancePhpunitConfigurationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function test_performance_suite_keeps_every_php_setting_of_the_root_configuration(): void
    {
        $root = self::phpSettings(self::ROOT . '/phpunit.xml');
        $performance = self::phpSettings(self::ROOT . '/tests/Performance/phpunit.xml');

        self::assertArrayHasKey('env LOG_LEVEL', $root);
        foreach ($root as $setting => $attributes) {
            self::assertSame(
                $attributes,
                $performance[$setting] ?? null,
                "tests/Performance/phpunit.xml precisa repetir <{$setting}> da phpunit.xml raiz, com os mesmos atributos"
            );
        }
    }

    /** @return array<string, array<string, string>> ex.: 'env LOG_LEVEL' => ['force' => 'true', 'name' => ..., 'value' => 'none'] */
    private static function phpSettings(string $file): array
    {
        $document = new DOMDocument();
        self::assertTrue($document->load($file), "{$file} não é XML válido");

        $settings = [];
        foreach ($document->getElementsByTagName('php') as $php) {
            foreach ($php->childNodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $attributes = [];
                foreach ($node->attributes as $attribute) {
                    $attributes[$attribute->name] = $attribute->value;
                }
                ksort($attributes);
                $settings[$node->tagName . ' ' . ($attributes['name'] ?? '')] = $attributes;
            }
        }

        return $settings;
    }
}
