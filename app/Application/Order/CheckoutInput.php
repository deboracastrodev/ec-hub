<?php

declare(strict_types=1);

namespace App\Application\Order;

/**
 * Checkout form, normalized and validated (Story 8.7), in the ProductInput
 * pattern: fromForm() never throws, errors() is keyed by field and values()
 * keeps what was typed so the form can be re-rendered.
 */
final class CheckoutInput
{
    public const FIELDS = ['name', 'email', 'address'];

    public const NAME_MIN = 2;
    public const NAME_MAX = 120;
    public const EMAIL_MAX = 254;
    public const ADDRESS_MIN = 10;
    public const ADDRESS_MAX = 500;

    /**
     * @param array{name: string, email: string, address: string} $values
     * @param array<string, string> $errors
     */
    private function __construct(private readonly array $values, private readonly array $errors)
    {
    }

    /** @param array<array-key, mixed> $form */
    public static function fromForm(array $form): self
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $raw = $form[$field] ?? '';
            // mb_scrub replaces invalid UTF-8 bytes, so MySQL/Twig never see them.
            $values[$field] = is_string($raw) ? trim(mb_scrub($raw, 'UTF-8')) : '';
        }
        // Browsers submit textarea line breaks as CRLF; count (and store) them
        // as one character each, like the maxlength the visitor saw.
        $values['address'] = str_replace(["\r\n", "\r"], "\n", $values['address']);

        $errors = [];

        $nameLength = mb_strlen($values['name']);
        if ($values['name'] === '') {
            $errors['name'] = 'Informe seu nome.';
        } elseif ($nameLength < self::NAME_MIN || $nameLength > self::NAME_MAX) {
            $errors['name'] = sprintf('O nome deve ter entre %d e %d caracteres.', self::NAME_MIN, self::NAME_MAX);
        } elseif (preg_match('/\p{Cc}/u', $values['name']) === 1) {
            $errors['name'] = 'O nome não pode ter quebras de linha nem caracteres de controle.';
        }

        if ($values['email'] === '') {
            $errors['email'] = 'Informe seu email.';
        } elseif (
            strlen($values['email']) > self::EMAIL_MAX
            || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'] = 'Informe um email válido (ex.: ana@exemplo.com).';
        }

        $addressLength = mb_strlen($values['address']);
        if ($values['address'] === '') {
            $errors['address'] = 'Informe o endereço de entrega.';
        } elseif ($addressLength < self::ADDRESS_MIN || $addressLength > self::ADDRESS_MAX) {
            $errors['address'] = sprintf('O endereço deve ter entre %d e %d caracteres.', self::ADDRESS_MIN, self::ADDRESS_MAX);
        } elseif (preg_match('/[^\P{Cc}\n\t]/u', $values['address']) === 1) {
            $errors['address'] = 'O endereço não pode ter caracteres de controle.';
        }

        /** @var array{name: string, email: string, address: string} $values */
        return new self($values, $errors);
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

    /** @return array{name: string, email: string, address: string} Normalized values, for re-rendering */
    public function values(): array
    {
        return $this->values;
    }

    public function name(): string
    {
        return $this->values['name'];
    }

    public function email(): string
    {
        return $this->values['email'];
    }

    public function address(): string
    {
        return $this->values['address'];
    }
}
