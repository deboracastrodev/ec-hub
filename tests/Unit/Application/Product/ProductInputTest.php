<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Product;

use App\Application\Product\ProductInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductInputTest extends TestCase
{
    /** @return array<string, string> */
    private static function validForm(): array
    {
        return [
            'name' => '  Mouse Sem Fio  ',
            'description' => 'Mouse ergonômico.',
            'price' => '1234,50',
            'category' => 'Periféricos',
            'image_url' => 'https://cdn.example.com/mouse.jpg',
        ];
    }

    public function testValidFormIsNormalized(): void
    {
        $input = ProductInput::fromForm(self::validForm());

        self::assertTrue($input->isValid());
        self::assertSame([], $input->errors());
        self::assertSame([
            'name' => 'Mouse Sem Fio',
            'description' => 'Mouse ergonômico.',
            'price' => '1234.50',
            'category' => 'Periféricos',
            'image_url' => 'https://cdn.example.com/mouse.jpg',
        ], $input->toData());
    }

    /** @return iterable<string, array{string, string}> */
    public static function acceptedPriceProvider(): iterable
    {
        yield 'comma decimal' => ['1234,50', '1234.50'];
        yield 'dot decimal' => ['1234.50', '1234.50'];
        yield 'integer' => ['10', '10.00'];
        yield 'one decimal' => ['0,5', '0.50'];
        yield 'max digits' => ['99999999,99', '99999999.99'];
        yield 'surrounding spaces' => [' 7,00 ', '7.00'];
    }

    #[DataProvider('acceptedPriceProvider')]
    public function testAcceptedPrices(string $price, string $expected): void
    {
        $input = ProductInput::fromForm(['price' => $price] + self::validForm());

        self::assertTrue($input->isValid(), implode(' ', $input->errors()));
        self::assertSame($expected, $input->toData()['price']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedPriceProvider(): iterable
    {
        yield 'thousand separator' => ['1.234,50'];
        yield 'thousand separator comma' => ['1,234.50'];
        yield 'letters' => ['abc'];
        yield 'zero' => ['0'];
        yield 'zero decimal' => ['0,00'];
        yield 'negative' => ['-5'];
        yield 'three decimals' => ['1,234'];
        yield 'nine integer digits' => ['123456789'];
        yield 'currency symbol' => ['R$ 10'];
        yield 'empty' => [''];
        yield 'missing' => [null];
        yield 'array' => [['10']];
    }

    #[DataProvider('rejectedPriceProvider')]
    public function testRejectedPrices(mixed $price): void
    {
        $form = self::validForm();
        if ($price === null) {
            unset($form['price']);
        } else {
            $form['price'] = $price;
        }

        $input = ProductInput::fromForm($form);

        self::assertFalse($input->isValid());
        self::assertSame(['price'], array_keys($input->errors()));
    }

    public function testNameRules(): void
    {
        self::assertArrayHasKey('name', ProductInput::fromForm(['name' => '   '] + self::validForm())->errors());
        self::assertArrayHasKey('name', ProductInput::fromForm(['name' => str_repeat('é', 256)] + self::validForm())->errors());
        self::assertTrue(ProductInput::fromForm(['name' => str_repeat('é', 255)] + self::validForm())->isValid());
    }

    public function testDescriptionIsOptionalUpTo5000Characters(): void
    {
        self::assertTrue(ProductInput::fromForm(['description' => ''] + self::validForm())->isValid());
        self::assertTrue(ProductInput::fromForm(['description' => str_repeat('ç', 5000)] + self::validForm())->isValid());
        self::assertArrayHasKey('description', ProductInput::fromForm(['description' => str_repeat('ç', 5001)] + self::validForm())->errors());
    }

    public function testDescriptionCrlfIsNormalizedBeforeTheLengthCheck(): void
    {
        // 1000 lines of "abcd\r\n" = 6000 chars raw, 5000 after normalization.
        $input = ProductInput::fromForm(['description' => str_repeat("abcd\r\n", 1000)] + self::validForm());

        self::assertTrue($input->isValid(), implode(' ', $input->errors()));
        self::assertStringNotContainsString("\r", $input->toData()['description']);
        self::assertSame("abcd\nabcd", ProductInput::fromForm(['description' => "abcd\r\nabcd"] + self::validForm())->toData()['description']);
        self::assertSame("a\nb", ProductInput::fromForm(['description' => "a\rb"] + self::validForm())->toData()['description']);
        self::assertTrue(mb_check_encoding(ProductInput::fromForm(['name' => "Teclado \xC3\x28"] + self::validForm())->toData()['name'], 'UTF-8'));
    }

    public function testCategoryRules(): void
    {
        self::assertArrayHasKey('category', ProductInput::fromForm(['category' => ''] + self::validForm())->errors());
        self::assertArrayHasKey('category', ProductInput::fromForm(['category' => str_repeat('a', 101)] + self::validForm())->errors());
        self::assertTrue(ProductInput::fromForm(['category' => str_repeat('a', 100)] + self::validForm())->isValid());
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedImageProvider(): iterable
    {
        yield 'https' => ['https://cdn.example.com/a.jpg'];
        yield 'http' => ['http://example.com/a.png'];
        yield 'site path' => ['/assets/images/a.jpg'];
    }

    #[DataProvider('acceptedImageProvider')]
    public function testAcceptedImageUrls(string $url): void
    {
        $input = ProductInput::fromForm(['image_url' => $url] + self::validForm());

        self::assertTrue($input->isValid());
        self::assertSame($url, $input->toData()['image_url']);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedImageProvider(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data uri' => ['data:image/png;base64,AAAA'];
        yield 'ftp' => ['ftp://example.com/a.jpg'];
        yield 'protocol relative' => ['//evil.example/a.jpg'];
        yield 'backslash protocol relative' => ['/\\evil.example/a.jpg'];
        yield 'relative path' => ['assets/a.jpg'];
        yield 'too long' => ['https://example.com/' . str_repeat('a', 490)];
    }

    #[DataProvider('rejectedImageProvider')]
    public function testRejectedImageUrls(string $url): void
    {
        $input = ProductInput::fromForm(['image_url' => $url] + self::validForm());

        self::assertSame(['image_url'], array_keys($input->errors()));
    }

    public function testEmptyImageUrlBecomesNull(): void
    {
        self::assertNull(ProductInput::fromForm(['image_url' => '  '] + self::validForm())->toData()['image_url']);
        $form = self::validForm();
        unset($form['image_url']);
        self::assertNull(ProductInput::fromForm($form)->toData()['image_url']);
    }

    public function testInvalidFormReportsEveryFieldAndKeepsTypedValues(): void
    {
        $input = ProductInput::fromForm([
            'name' => '',
            'price' => 'abc',
            'category' => ['x'],
            'image_url' => 'javascript:alert(1)',
            'description' => '<b>ok</b>',
        ]);

        self::assertFalse($input->isValid());
        self::assertEqualsCanonicalizing(['name', 'price', 'category', 'image_url'], array_keys($input->errors()));
        self::assertSame('abc', $input->values()['price']);
        self::assertSame('javascript:alert(1)', $input->values()['image_url']);
        self::assertSame('', $input->values()['category']);
        self::assertSame('<b>ok</b>', $input->values()['description']);
    }

    public function testEmptyFormDoesNotThrow(): void
    {
        $input = ProductInput::fromForm([]);

        self::assertEqualsCanonicalizing(['name', 'price', 'category'], array_keys($input->errors()));
    }

    public function testInvalidInputCannotBePersisted(): void
    {
        $this->expectException(\LogicException::class);

        ProductInput::fromForm([])->toData();
    }
}
