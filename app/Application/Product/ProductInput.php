<?php

declare(strict_types=1);

namespace App\Application\Product;

/**
 * Admin product form, normalized and validated (Story 8.3).
 *
 * fromForm() never throws: invalid input is reported through errors(),
 * keyed by field, and values() keeps what was typed so the form can be
 * re-rendered. A missing or non-string field counts as empty.
 */
final class ProductInput
{
    public const FIELDS = ['name', 'description', 'price', 'category', 'image_url'];

    private const PRICE_PATTERN = '/^\d{1,8}([.,]\d{1,2})?\z/';

    /**
     * @param array<string, string> $values Trimmed form values, one per FIELDS entry
     * @param array<string, string> $errors
     */
    private function __construct(
        private readonly array $values,
        private readonly array $errors,
        private readonly ?string $normalizedPrice
    ) {
    }

    /** @param array<array-key, mixed> $form */
    public static function fromForm(array $form): self
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $raw = $form[$field] ?? '';
            // mb_scrub troca bytes UTF-8 inválidos por '?', evitando erro do MySQL/Twig.
            $values[$field] = is_string($raw) ? trim(mb_scrub($raw, 'UTF-8')) : '';
        }
        // Browsers submit textarea line breaks as CRLF; count (and store) them
        // as one character each, like the maxlength the admin saw.
        $values['description'] = str_replace(["\r\n", "\r"], "\n", $values['description']);

        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = 'Informe o nome do produto.';
        } elseif (mb_strlen($values['name']) > 255) {
            $errors['name'] = 'O nome pode ter no máximo 255 caracteres.';
        }

        if (mb_strlen($values['description']) > 5000) {
            $errors['description'] = 'A descrição pode ter no máximo 5000 caracteres.';
        }

        $normalizedPrice = null;
        if ($values['price'] === '') {
            $errors['price'] = 'Informe o preço.';
        } elseif (preg_match(self::PRICE_PATTERN, $values['price']) !== 1) {
            $errors['price'] = 'Preço inválido. Use apenas números, com até 2 casas decimais (ex.: 1234,50), sem separador de milhar.';
        } else {
            $decimal = str_replace(',', '.', $values['price']);
            if ((float) $decimal <= 0) {
                $errors['price'] = 'O preço deve ser maior que zero.';
            } else {
                $normalizedPrice = number_format((float) $decimal, 2, '.', '');
            }
        }

        if ($values['category'] === '') {
            $errors['category'] = 'Informe a categoria.';
        } elseif (mb_strlen($values['category']) > 100) {
            $errors['category'] = 'A categoria pode ter no máximo 100 caracteres.';
        }

        if ($values['image_url'] !== '') {
            if (mb_strlen($values['image_url']) > 500) {
                $errors['image_url'] = 'A URL da imagem pode ter no máximo 500 caracteres.';
            } elseif (! self::isAcceptedImageUrl($values['image_url'])) {
                $errors['image_url'] = 'Use uma URL http(s) válida ou um caminho do site começando com "/".';
            }
        }

        return new self($values, $errors, $normalizedPrice);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, string> Messages keyed by field */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, string> Trimmed values as typed, for re-rendering */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * The repository payload. Only meaningful for a valid input.
     *
     * @return array{name: string, description: string, price: string, category: string, image_url: ?string}
     */
    public function toData(): array
    {
        if (! $this->isValid() || $this->normalizedPrice === null) {
            throw new \LogicException('ProductInput inválido não pode ser persistido.');
        }

        return [
            'name' => $this->values['name'],
            'description' => $this->values['description'],
            'price' => $this->normalizedPrice,
            'category' => $this->values['category'],
            'image_url' => $this->values['image_url'] === '' ? null : $this->values['image_url'],
        ];
    }

    /**
     * An absolute http(s) URL, or a site path starting with a single "/".
     * Backslashes, whitespace and control characters are refused in paths:
     * browsers read "/\host" as the protocol-relative "//host".
     */
    private static function isAcceptedImageUrl(string $value): bool
    {
        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//') && preg_match('/[\\\\\s\x00-\x1F\x7F]/', $value) !== 1;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }
}
