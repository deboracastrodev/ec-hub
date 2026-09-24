<?php

declare(strict_types=1);

namespace Tests\Fixtures\Phpunit12;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exemplo do que NÃO fazer no PHPUnit 12, citado no LEARNING_JOURNAL.md
 * (Desafio 3, "O que NÃO fazer"). Cada método usa um hábito da era PHPUnit 8
 * que o PHPUnit 12 não aceita mais, e a saída real está fixada em
 * tests/Integration/Tooling/Phpunit12LegacyMetadataTest.php.
 *
 * Este arquivo fica fora de todas as suítes do phpunit.xml (tests/Fixtures não
 * está em nenhum <testsuite>) e só roda quando chamado explicitamente:
 *
 *   vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
 *     tests/Fixtures/Phpunit12/LegacyMetadataExample.php
 */
final class LegacyMetadataExample extends TestCase
{
    /**
     * Anotação em docblock: o PHPUnit 12 ignora, o método é chamado sem
     * argumentos e dá ArgumentCountError.
     *
     * @dataProvider precos
     */
    public function test_docblock_data_provider(float $preco): void
    {
        $this->assertGreaterThan(0, $preco);
    }

    /** @return array<string, array{float}> */
    public static function precos(): array
    {
        return ['dez reais' => [10.0]];
    }

    /**
     * Grupo em docblock: o PHPUnit 12 ignora, o grupo "db" não existe, e
     * --exclude-group db não tira este teste da execução.
     *
     * @group db
     */
    public function test_docblock_group_db(): void
    {
        $this->assertTrue(true);
    }

    /**
     * Atributo certo, provider errado: sem static, o PHPUnit 12 registra
     * "Data Provider method ...() is not static" e o teste nem é contado.
     */
    #[DataProvider('naoStatic')]
    public function test_non_static_data_provider(float $preco): void
    {
        $this->assertGreaterThan(0, $preco);
    }

    /** @return array<string, array{float}> */
    public function naoStatic(): array
    {
        return ['dez reais' => [10.0]];
    }

    /**
     * assertRegExp não existe no PHPUnit 12: o substituto é assertMatchesRegularExpression.
     */
    public function test_removed_assert_reg_exp(): void
    {
        $this->assertRegExp('/^\d+ms$/', '12ms');
    }
}
