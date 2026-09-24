<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Order;

use App\Application\Order\CheckoutInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckoutInputTest extends TestCase
{
    private const VALID = ['name' => 'Ana Souza', 'email' => 'ana@example.com', 'address' => 'Rua das Flores, 123 - São Paulo'];

    public function testValidInputIsTrimmed(): void
    {
        $input = CheckoutInput::fromForm([
            'name' => '  Ana Souza ',
            'email' => ' ana@example.com ',
            'address' => " Rua das Flores, 123\r\nSão Paulo\rSP ",
        ]);

        self::assertTrue($input->isValid());
        self::assertSame([], $input->errors());
        self::assertSame('Ana Souza', $input->name());
        self::assertSame('ana@example.com', $input->email());
        self::assertSame("Rua das Flores, 123\nSão Paulo\nSP", $input->address());
        self::assertSame(['name' => 'Ana Souza', 'email' => 'ana@example.com', 'address' => "Rua das Flores, 123\nSão Paulo\nSP"], $input->values());
    }

    public function testMissingAndNonStringFieldsCountAsEmpty(): void
    {
        $input = CheckoutInput::fromForm(['name' => ['x'], 'email' => 42]);

        self::assertFalse($input->isValid());
        self::assertSame(['name', 'email', 'address'], array_keys($input->errors()));
        self::assertSame(['name' => '', 'email' => '', 'address' => ''], $input->values());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFields(): iterable
    {
        yield 'name empty' => ['name', '   '];
        yield 'name 1 char' => ['name', 'A'];
        yield 'name 121 chars' => ['name', str_repeat('a', 121)];
        yield 'name with newline' => ['name', "Ana\nBcc: x"];
        yield 'name with control' => ['name', "Ana\x07Souza"];
        yield 'email empty' => ['email', ''];
        yield 'email incomplete' => ['email', 'ana@'];
        yield 'email header injection' => ['email', "ana@example.com\r\nBcc: x@example.com"];
        yield 'email too long' => ['email', str_repeat('a', 64) . '@' . str_repeat('b', 185) . '.com'];
        yield 'address empty' => ['address', ''];
        yield 'address short' => ['address', 'Rua A'];
        yield 'address 501 chars' => ['address', str_repeat('a', 501)];
        yield 'address control char' => ['address', "Rua das Flores\x00, 123"];
    }

    #[DataProvider('invalidFields')]
    public function testEachRule(string $field, string $value): void
    {
        $input = CheckoutInput::fromForm([$field => $value] + self::VALID);

        self::assertFalse($input->isValid());
        self::assertSame([$field], array_keys($input->errors()));
        self::assertNotSame('', $input->errors()[$field]);
    }

    public function testLimitsAreInclusiveAndCountCharacters(): void
    {
        $input = CheckoutInput::fromForm([
            'name' => 'Jó',
            'email' => self::VALID['email'],
            'address' => str_repeat('ã', 500),
        ]);
        self::assertTrue($input->isValid(), json_encode($input->errors(), JSON_THROW_ON_ERROR));

        self::assertTrue(CheckoutInput::fromForm(['name' => str_repeat('é', 120)] + self::VALID)->isValid());
        self::assertTrue(CheckoutInput::fromForm(['address' => str_repeat('a', 10)] + self::VALID)->isValid());
        // CRLF counts as one character: 250 lines of "a\r\n" minus the trimmed tail = 499.
        self::assertTrue(CheckoutInput::fromForm(['address' => str_repeat("a\r\n", 250)] + self::VALID)->isValid());
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        $input = CheckoutInput::fromForm(['name' => "Ana \xC3\x28"] + self::VALID);

        self::assertTrue(mb_check_encoding($input->name(), 'UTF-8'));
    }
}
